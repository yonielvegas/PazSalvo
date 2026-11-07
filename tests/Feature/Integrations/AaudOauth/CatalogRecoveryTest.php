<?php

namespace Tests\Feature\Integrations\AaudOauth;

use App\Integrations\AaudOauth\Access\AccessOutbox;
use App\Integrations\AaudOauth\Access\AccessSnapshotBuilder;
use App\Integrations\AaudOauth\Exceptions\AaudOauthRequestException;
use App\Integrations\AaudOauth\Roles\CatalogOutbox;
use App\Integrations\AaudOauth\Roles\CatalogRecovery;
use App\Integrations\AaudOauth\Roles\RoleCatalogBuilder;
use App\Models\User;
use App\Models\UserSsoIdentity;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CatalogRecoveryTest extends TestCase
{
    use DatabaseTruncation;

    private User $admin;

    private User $user;

    private array $remote;

    private array $accessState = [];

    private array $puts = [];

    private bool $unavailable = false;

    private int $operation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aaud-oauth.automatic' => false, 'aaud-oauth.enabled' => true, 'aaud-oauth.issuer' => 'https://id.example.test/realms/aaud',
            'aaud-oauth.api_url' => 'https://auth.example.test', 'aaud-oauth.token_url' => 'https://id.example.test/token',
            'aaud-oauth.client_id' => 'pazysalvo-sync', 'aaud-oauth.client_secret' => 'fixture-only']);
        Http::preventStrayRequests();
        $this->seed(MasterDataSeeder::class);
        DB::table('aaud_auth_catalog_states')->insertOrIgnore(['application_code' => 'pazysalvo', 'resource' => 'roles', 'created_at' => now(), 'updated_at' => now()]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->user = User::factory()->create();
        $this->user->assignRole('operador');
        UserSsoIdentity::create(['user_id' => $this->user->id, 'issuer' => config('aaud-oauth.issuer'), 'subject' => 'recovery-subject']);
        $outbox = app(AccessOutbox::class);
        $snapshot = app(AccessSnapshotBuilder::class)->build($this->user);
        $outbox->recordRemoteObservation(app(AccessSnapshotBuilder::class)->revocation($snapshot->payload()), 0, true, 'TEST-ABSENCE', $this->admin);
        $this->operation = $outbox->prepare($this->user, $this->admin)->id;
        DB::table('aaud_auth_access_targets')->update(['delivery_error' => 'needs_review', 'next_delivery_at' => null]);
        $this->remote = ['database' => 'aaud', 'read_only' => 'on', 'application_code' => 'pazysalvo', 'application_active' => true,
            'version' => 2, 'observed_at' => time(), 'client_verified' => true,
            'roles' => array_map(fn ($role) => [...$role, 'deleted_at_source' => false, 'assignments' => 0], app(RoleCatalogBuilder::class)->build())];
        Process::fake(fn () => Process::result(output: json_encode([...$this->remote, 'observed_at' => time()]), exitCode: 0));
        Http::fake(function ($request) {
            $this->assertSame(0, DB::transactionLevel(), 'HTTP cannot run within an administrative transaction');
            if (str_ends_with($request->url(), '/token')) {
                return Http::response(['access_token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'roles.write access.write']);
            }
            if ($request->method() === 'GET') {
                return Http::response(['data' => $this->accessState ?: ['issuer' => config('aaud-oauth.issuer'), 'subject' => 'recovery-subject',
                    'application_code' => 'pazysalvo', 'application_user_id' => null, 'exists' => false, 'is_active' => false, 'roles' => [], 'version' => 0]]);
            }
            $this->puts[] = ['url' => $request->url(), 'payload' => $request->data()];
            if (str_ends_with($request->url(), '/roles')) {
                if ($this->unavailable) {
                    return Http::response([], 503);
                }
                $this->remote['version'] = $request['version'];
                $this->remote['roles'] = array_map(fn ($role) => [...$role, 'deleted_at_source' => false, 'assignments' => 0], $request['roles']);

                return Http::response(['status' => 'applied', 'data' => $request->data() + ['application_code' => 'pazysalvo'], 'current_version' => $request['version'], 'historical' => false]);
            }
            $payload = $request->data() + ['subject' => 'recovery-subject'];
            $this->accessState = array_intersect_key($payload, array_flip(['issuer', 'subject', 'application_user_id', 'is_active', 'roles', 'version'])) + ['application_code' => 'pazysalvo', 'exists' => true];

            return Http::response(['status' => 'applied', 'data' => $payload + ['application_code' => 'pazysalvo', 'confirmed_at' => now()->toIso8601String()],
                'current_version' => $payload['version'], 'historical' => false]);
        });
        config(['aaud-oauth.automatic' => true]);
    }

    protected function tearDown(): void
    {
        config(['aaud-oauth.automatic' => false]);
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    private function extraRole(): void
    {
        $this->remote['roles'][] = ['code' => 'prueba', 'name' => 'Prueba de sincronizacion rol', 'active' => true, 'deleted_at_source' => false, 'assignments' => 0];
    }

    public function test_preview_reads_remote_version_two_after_local_history_loss_without_writes_or_http(): void
    {
        $this->extraRole();
        $before = DB::table('aaud_auth_catalog_states')->first();
        $plan = app(CatalogRecovery::class)->plan(2);
        $this->assertSame(3, $plan['candidate_version']);
        $this->assertSame('prueba', $plan['omitted_remote_roles'][0]['code']);
        $this->assertEquals($before, DB::table('aaud_auth_catalog_states')->first());
        $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'aaud_oauth.catalog.reconciled_snapshot']);
        Http::assertNothingSent();
        Process::assertRan(fn ($process) => str_contains($process->input, 'REPEATABLE READ READ ONLY'));
    }

    public function test_matching_catalog_reconciles_without_fabricating_a_receipt_and_delivers_same_access(): void
    {
        $outbox = app(AccessOutbox::class);
        $before = $outbox->get($this->operation)->payload();
        $service = app(CatalogRecovery::class);
        $plan = $service->plan(2);
        $this->assertNull($plan['candidate_version']);
        $result = $service->execute(2, $plan['approval_hash'], $this->admin);
        $this->assertSame(2, $result['catalog_version']);
        $this->assertTrue($result['targets'][(string) $this->user->id]);
        $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
        $this->assertNull(DB::table('aaud_auth_catalog_states')->value('last_confirmed_at'));
        $this->assertTrue(app(CatalogOutbox::class)->isCurrentlySynchronized());
        $this->assertSame($before, $outbox->get($this->operation)->payload());
        $this->assertDatabaseCount('aaud_auth_access_operations', 1);
        $this->assertSame('confirmed', $outbox->get($this->operation)->status);
        $this->assertCount(1, $this->puts);
        $this->assertStringContainsString('/users/recovery-subject/access', $this->puts[0]['url']);
    }

    public function test_different_catalog_publishes_three_first_then_resumes_original_access(): void
    {
        $this->extraRole();
        $service = app(CatalogRecovery::class);
        $plan = $service->plan(2);
        $before = app(AccessOutbox::class)->get($this->operation)->payload();
        $service->execute(2, $plan['approval_hash'], $this->admin);
        $this->assertSame(3, $this->remote['version']);
        $this->assertSame($plan['roles'], array_map(fn ($r) => array_intersect_key($r, array_flip(['code', 'name', 'active'])), $this->remote['roles']));
        $this->assertDatabaseHas('aaud_auth_catalog_operations', ['status' => 'confirmed', 'version' => 3]);
        $this->assertCount(2, $this->puts);
        $this->assertStringEndsWith('/roles', $this->puts[0]['url']);
        $this->assertSame($before, app(AccessOutbox::class)->get($this->operation)->payload());
        $this->assertSame(['operador'], $this->accessState['roles']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'aaud_oauth.access.catalog_recovery_resumed']);
    }

    public function test_changed_remote_version_stops_before_observation_reservation_or_http(): void
    {
        $this->remote['version'] = 3;
        try {
            app(CatalogRecovery::class)->plan(2);
            $this->fail('Changed remote version must stop recovery');
        } catch (\RuntimeException) {
            $this->assertNull(DB::table('aaud_auth_catalog_states')->value('last_remote_version'));
            $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
            Http::assertNothingSent();
        }
    }

    public function test_changed_local_catalog_or_remote_assignments_invalidates_the_approved_plan(): void
    {
        $plan = app(CatalogRecovery::class)->plan(2);
        $this->remote['roles'][0]['assignments'] = 2;
        // Assignment counts of preserved roles are not part of destructive effects; change a role instead.
        $this->extraRole();
        try {
            app(CatalogRecovery::class)->execute(2, $plan['approval_hash'], $this->admin);
            $this->fail('A changed plan cannot execute');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
            Http::assertNothingSent();
        }
    }

    public function test_failed_catalog_delivery_keeps_target_paused_and_access_payload_immutable(): void
    {
        $this->extraRole();
        $this->unavailable = true;
        $service = app(CatalogRecovery::class);
        $plan = $service->plan(2);
        $before = app(AccessOutbox::class)->get($this->operation)->payload();
        try {
            $service->execute(2, $plan['approval_hash'], $this->admin);
            $this->fail('A lost catalog response cannot confirm recovery');
        } catch (\RuntimeException) {
            $this->assertDatabaseHas('aaud_auth_access_targets', ['delivery_error' => 'needs_review']);
            $this->assertSame($before, app(AccessOutbox::class)->get($this->operation)->payload());
            $this->assertSame('pending', app(AccessOutbox::class)->get($this->operation)->status);
            $this->assertDatabaseHas('aaud_auth_catalog_operations', ['version' => 3, 'status' => 'uncertain']);
            $this->assertCount(1, $this->puts);
        }
    }

    public function test_unsafe_read_only_evidence_and_unauthorized_recovery_are_rejected(): void
    {
        $this->remote['read_only'] = 'off';
        try {
            app(CatalogRecovery::class)->plan(2);
            $this->fail('Read-write evidence must be rejected');
        } catch (\RuntimeException) {
        }
        $this->remote['read_only'] = 'on';
        $plan = app(CatalogRecovery::class)->plan(2);
        try {
            app(CatalogRecovery::class)->execute(2, $plan['approval_hash'], $this->user);
            $this->fail('Non-admin cannot recover');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
            Http::assertNothingSent();
        }
    }

    public function test_terminal_or_uncertain_access_is_never_blindly_unblocked(): void
    {
        app(CatalogOutbox::class)->reconcileObservedCatalog([...$this->remote, 'observed_at' => time()], 'TEST-OBSERVED', $this->admin);
        $outbox = app(AccessOutbox::class);
        $claim = $outbox->claim($this->operation);
        $outbox->recordFailure($this->operation, $claim->claimId, new AaudOauthRequestException('timeout'));
        $before = $outbox->get($this->operation)->payload();
        try {
            app(CatalogRecovery::class)->resumeTarget((string) $this->user->id, $this->admin, 'TEST-RESUME');
            $this->fail('An uncertain PUT requires its own reconciliation');
        } catch (\RuntimeException) {
            $this->assertSame('uncertain', $outbox->get($this->operation)->status);
            $this->assertSame($before, $outbox->get($this->operation)->payload());
            Http::assertNothingSent();
        }
    }
}
