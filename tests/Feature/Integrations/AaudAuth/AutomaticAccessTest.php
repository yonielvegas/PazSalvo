<?php

namespace Tests\Feature\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Access\AccessDelivery;
use App\Integrations\AaudAuth\Access\AccessOutbox;
use App\Integrations\AaudAuth\Access\AccessSnapshotBuilder;
use App\Integrations\AaudAuth\Http\RoleCatalogResult;
use App\Integrations\AaudAuth\Roles\CatalogOutbox;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSsoIdentity;
use App\Services\AdministrativeTransaction;
use App\Services\AuditLogger;
use App\Services\UserSsoIdentityManager;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomaticAccessTest extends TestCase
{
    use DatabaseTruncation;

    private User $actor;

    private User $user;

    private array $remote = [];

    private array $sent = [];

    private bool $offline = false;

    private bool $lostResponse = false;

    private bool $reject = false;

    private bool $catalogOffline = false;

    private bool $dropBeforeApply = false;

    private bool $versionConflict = false;

    private bool $historicalReceipt = false;

    private bool $catalogRejected = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aaud-auth.automatic' => false, 'aaud-auth.enabled' => true, 'aaud-auth.issuer' => 'https://id.example.test/realms/aaud',
            'aaud-auth.api_url' => 'https://auth.example.test', 'aaud-auth.token_url' => 'https://id.example.test/token',
            'aaud-auth.client_secret' => 'fixture-private', 'aaud-auth.client_id' => 'pazysalvo-sync']);
        Http::preventStrayRequests();
        $this->seed(MasterDataSeeder::class);
        $this->actor = User::factory()->create();
        $this->actor->assignRole('admin');
        $this->user = User::factory()->create();
        $this->user->assignRole('operador');
        $this->actingAs($this->actor);
        DB::table('aaud_auth_catalog_states')->insertOrIgnore(['application_code' => 'pazysalvo', 'resource' => 'roles', 'created_at' => now(), 'updated_at' => now()]);
        $catalog = app(CatalogOutbox::class);
        $catalog->recordRemoteObservation(0, 'TEST-FIXTURE', $this->actor);
        $op = $catalog->prepare();
        $claim = $catalog->claim($op->id);
        $catalog->confirm($op->id, $claim->claimId, new RoleCatalogResult('applied', 1, $op->operationId, 1, false,
            array_map(fn ($role) => [...$role, 'description' => null], $op->payload()['roles'])));
        Http::fake(function ($request) {
            $this->assertSame(0, DB::transactionLevel(), 'HTTP must be after commit and outside PostgreSQL transactions');
            if ($this->offline) {
                throw new ConnectionException('connection unavailable');
            }
            if ($request->url() === 'https://id.example.test/token') {
                return Http::response(['access_token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'access.write roles.write']);
            }
            if (str_contains($request->url(), '/directory/users')) {
                return Http::response(['data' => [['issuer' => config('aaud-auth.issuer'), 'subject' => 'subject-A', 'username' => 'institutional.user',
                    'name' => 'Institutional Test', 'name_is_fallback' => false, 'email' => 'institutional@example.test', 'enabled' => true]], 'page' => 1, 'limit' => 20, 'has_more' => false]);
            }
            if (str_ends_with($request->url(), '/roles')) {
                if ($this->catalogRejected) {
                    return Http::response(['error' => ['code' => 'client_not_authorized']], 403);
                }
                if ($this->catalogOffline) {
                    return Http::response([], 503);
                }

                return Http::response(['status' => 'applied', 'data' => [...$request->data(), 'application_code' => 'pazysalvo'], 'current_version' => $request['version'], 'historical' => false]);
            }
            preg_match('~/users/([^/?]+)/access~', $request->url(), $m);
            if (! isset($m[1])) {
                throw new \LogicException('Unexpected HTTP request');
            }
            $subject = $m[1];
            if ($request->method() === 'GET') {
                return Http::response(['data' => $this->remote[$subject] ?? ['issuer' => config('aaud-auth.issuer'), 'subject' => $subject,
                    'application_code' => 'pazysalvo', 'application_user_id' => null, 'exists' => false, 'is_active' => false, 'roles' => [], 'version' => 0]]);
            }
            $payload = $request->data() + ['subject' => $subject];
            $this->sent[] = $payload;
            if ($this->dropBeforeApply) {
                throw new ConnectionException('timeout');
            }
            if ($this->versionConflict) {
                $this->remote[$subject] = ['issuer' => $payload['issuer'], 'subject' => $subject, 'application_code' => 'pazysalvo',
                    'application_user_id' => $payload['application_user_id'], 'exists' => true, 'is_active' => false, 'roles' => [], 'version' => 7];

                return Http::response(['error' => ['code' => 'stale_version']], 409);
            }
            if ($this->reject) {
                return Http::response(['error' => ['code' => 'client_not_authorized']], 403);
            }
            $this->remote[$subject] = array_intersect_key($payload, array_flip(['issuer', 'subject', 'application_user_id', 'is_active', 'roles', 'version'])) + ['application_code' => 'pazysalvo', 'exists' => true];
            if ($this->historicalReceipt) {
                $this->remote[$subject]['version']++;
                $this->remote[$subject]['is_active'] = false;
                $this->remote[$subject]['roles'] = [];

                return Http::response(['status' => 'duplicate', 'data' => $payload + ['application_code' => 'pazysalvo', 'confirmed_at' => now()->toIso8601String()],
                    'current_version' => $payload['version'] + 1, 'historical' => true,
                    'current_state' => ['version' => $payload['version'] + 1, 'is_active' => false, 'roles' => [], 'catalog_version' => 1]]);
            }
            if ($this->lostResponse) {
                throw new ConnectionException('timeout');
            }

            return Http::response(['status' => 'applied', 'data' => $payload + ['application_code' => 'pazysalvo', 'confirmed_at' => now()->toIso8601String()],
                'current_version' => $payload['version'], 'historical' => false]);
        });
        config(['aaud-auth.automatic' => true]);
    }

    protected function tearDown(): void
    {
        config(['aaud-auth.automatic' => false]);
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    private function link(): void
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->actor);
        app(UserSsoIdentityManager::class)->link($this->user, $this->actor,
            ['issuer' => config('aaud-auth.issuer'), 'subject' => 'subject-A', 'query' => 'institutional', 'page' => 1], $request);
    }

    private function storedLink(string $subject = 'subject-A'): void
    {
        UserSsoIdentity::create(['user_id' => $this->user->id, 'issuer' => config('aaud-auth.issuer'), 'subject' => $subject]);
    }

    public function test_independent_delivery_processes_never_double_claim_or_put(): void
    {
        $this->storedLink();
        app(AccessOutbox::class)->prepare($this->user, $this->actor);
        $dir = sys_get_temp_dir().'/automatic-access-race-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $workers = [];
        $start = function ($name, $release) use ($dir, &$workers) {
            $process = proc_open([PHP_BINARY, base_path('tests/helpers/automatic-access-worker.php'), (string) $this->user->id, $dir.'/'.$name, $release],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($process);
            $workers[] = [$process, $pipes];
        };
        $await = function ($condition) {
            $end = microtime(true) + 10;
            while (! $condition() && microtime(true) < $end) {
                usleep(10000);
            }
            $this->assertTrue($condition());
        };
        try {
            $start('one', $dir.'/release');
            $await(fn () => is_file($dir.'/one'));
            $start('two', '-');
            $await(fn () => is_file($dir.'/two.started'));
            $this->assertNotSame(file_get_contents($dir.'/one.started'), file_get_contents($dir.'/two.started'));
            // Process two must finish while process one is still inside the fake GET.
            [$second, $pipes] = $workers[1];
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($second), $errors);
            $this->assertSame(0, json_decode($output, true)['puts']);
            $this->assertSame(1, (int) DB::table('aaud_auth_access_targets')->value('delivery_attempts'));
            file_put_contents($dir.'/release', 'go');
            [$first, $pipes] = $workers[0];
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($first), $errors);
            $this->assertSame(1, json_decode($output, true)['puts']);
            $this->assertDatabaseHas('aaud_auth_access_operations', ['status' => 'confirmed', 'attempts' => 1]);
            Http::assertNothingSent();
        } finally {
            file_put_contents($dir.'/release', 'go');
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_close($process);
                }
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_expired_target_claim_is_recovered_and_read_failures_have_a_durable_limit(): void
    {
        $this->storedLink();
        app(AccessOutbox::class)->prepare($this->user, $this->actor);
        DB::table('aaud_auth_access_targets')->update(['delivery_claim' => (string) Str::uuid(), 'delivery_lease_until' => now()->subMinute()]);
        app(AccessDelivery::class)->recover();
        $this->assertSame(1, $this->remote['subject-A']['version']);
        $this->offline = true;
        $this->user->syncRoles('consulta');
        for ($i = 0; $i < 10; $i++) {
            $this->travel(2)->hours();
            app(AccessDelivery::class)->recover();
        }
        $this->assertDatabaseHas('aaud_auth_access_targets', ['delivery_attempts' => 8, 'delivery_error' => 'needs_review']);
        $this->assertSame(1, $this->remote['subject-A']['version']);
    }

    public function test_revocation_survives_user_deletion_and_eventually_delivers(): void
    {
        $this->link();
        $this->offline = true;
        $id = $this->user->id;
        $this->delete('/admin/users/'.$id)->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $id]);
        $this->assertDatabaseHas('aaud_auth_access_targets', ['application_user_id' => (string) $id]);
        $this->offline = false;
        $this->travel(3)->minutes();
        app(AccessDelivery::class)->recover();
        $this->assertFalse($this->remote['subject-A']['is_active']);
        $this->assertSame([], $this->remote['subject-A']['roles']);
    }

    public function test_existing_remote_version_is_observed_instead_of_assuming_one(): void
    {
        $this->storedLink();
        $this->remote['subject-A'] = ['issuer' => config('aaud-auth.issuer'), 'subject' => 'subject-A', 'application_code' => 'pazysalvo',
            'application_user_id' => (string) $this->user->id, 'exists' => true, 'is_active' => false, 'roles' => [], 'version' => 17];
        $this->user->syncRoles('consulta');
        $this->assertSame(18, $this->remote['subject-A']['version']);
        $this->assertDatabaseHas('aaud_auth_access_operations', ['version' => 18, 'status' => 'confirmed']);
    }

    public function test_historical_initialization_requires_authorization_and_is_idempotent(): void
    {
        $this->storedLink();
        $this->artisan('aaud-auth:initialize-access', ['user' => [(string) $this->user->id]])->assertSuccessful();
        $this->assertDatabaseCount('aaud_auth_access_targets', 0);
        Http::assertNothingSent();
        $this->artisan('aaud-auth:initialize-access', ['user' => [(string) $this->user->id], '--authorize' => true, '--approved-by' => (string) $this->actor->id])->assertSuccessful();
        $this->assertTrue($this->remote['subject-A']['is_active']);
        $this->artisan('aaud-auth:initialize-access', ['user' => [(string) $this->user->id], '--authorize' => true, '--approved-by' => (string) $this->actor->id])->assertSuccessful();
        $this->assertDatabaseCount('aaud_auth_access_operations', 1);
    }

    public function test_create_with_sso_confirms_user_role_link_and_delivery_together(): void
    {
        $this->post('/admin/users', ['name' => 'Local draft', 'email' => 'draft@example.test', 'agency_id' => $this->actor->agency_id,
            'role' => 'operador', 'is_active' => true, 'sso_identity' => ['issuer' => config('aaud-auth.issuer'),
                'subject' => 'subject-A', 'query' => 'institutional', 'page' => 1, 'confirmed' => true]])->assertSessionHasNoErrors()->assertRedirect();
        $user = User::where('email', 'institutional@example.test')->sole();
        $this->assertSame((string) $user->id, $this->remote['subject-A']['application_user_id']);
        $this->assertSame('operador', $user->roles->sole()->code);
        $this->assertNotNull($user->ssoIdentity);
        $this->assertTrue($user->must_change_password);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.created', 'result' => 'success']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.sso.linked', 'result' => 'success']);
    }

    public function test_local_creation_does_not_publish_or_enroll_an_access(): void
    {
        $this->post('/admin/users', ['name' => 'Local test', 'email' => 'local.test@example.test', 'agency_id' => $this->actor->agency_id,
            'role' => 'operador', 'is_active' => true])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('aaud_auth_access_targets', 0);
        Http::assertNothingSent();
    }

    public function test_delivery_receipt_audit_failure_does_not_undo_committed_local_changes(): void
    {
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function ($event, $metadata = [], $subject = null, $request = null, $result = null) {
            if ($event === 'aaud_auth.access.confirmed') {
                throw new \RuntimeException('Post-commit audit storage failure');
            }

            return (new AuditLogger)->record($event, $metadata, $subject, $request, $result);
        });
        $this->link();
        $this->assertTrue($this->user->ssoIdentity()->exists());
        $this->assertDatabaseHas('aaud_auth_access_operations', ['status' => 'processing']);
        $this->assertSame('operador', $this->user->fresh()->roles->sole()->code);
        $this->travel(5)->minutes();
        app(AccessDelivery::class)->recover();
        $this->assertDatabaseHas('aaud_auth_access_operations', ['status' => 'cancelled']);
        $this->assertTrue(app(AccessOutbox::class)->status((string) $this->user->id)['currently_synchronized']);
    }

    public function test_delivery_recovery_migration_rolls_back_without_losing_durable_payloads(): void
    {
        $this->storedLink();
        app(AccessOutbox::class)->prepare($this->user, $this->actor);
        $migration = require base_path('database/migrations/2026_10_23_000001_add_access_delivery_recovery.php');
        config(['aaud-auth.automatic' => false]);
        $before = DB::table('aaud_auth_access_targets')->value('desired');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('aaud_auth_access_targets', 'delivery_claim'));
        $this->assertSame($before, DB::table('aaud_auth_access_targets')->value('desired'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('aaud_auth_access_targets', 'delivery_claim'));
        $this->assertSame($before, DB::table('aaud_auth_access_targets')->value('desired'));
    }

    public function test_never_sent_obsolete_grant_is_cancelled_before_deactivation_can_publish_it(): void
    {
        $this->storedLink();
        $outbox = app(AccessOutbox::class);
        $snapshot = app(AccessSnapshotBuilder::class)->build($this->user);
        $outbox->recordRemoteObservation(app(AccessSnapshotBuilder::class)->revocation($snapshot->payload()), 0, true, 'TEST-ABSENCE', $this->actor);
        $operation = $outbox->prepare($this->user, $this->actor);
        $this->user->update(['is_active' => false]);
        $this->assertSame([], $this->sent);
        $this->assertSame('cancelled', $outbox->get($operation->id)->status);
        $this->assertSame(0, $outbox->get($operation->id)->attempts);
        $this->assertDatabaseHas('audit_logs', ['event' => 'aaud_auth.access.unsent_superseded']);
        $this->user->update(['is_active' => true]);
        $this->assertSame(2, $this->remote['subject-A']['version']);
    }

    public function test_uncertain_delivery_below_remote_fence_replays_exact_original_operation(): void
    {
        $this->dropBeforeApply = true;
        $this->link();
        $first = $this->sent[0];
        $this->dropBeforeApply = false;
        $this->travel(3)->minutes();
        app(AccessDelivery::class)->recover();
        $this->assertSame($first, $this->sent[1]);
        $this->assertDatabaseCount('aaud_auth_access_operations', 1);
        $this->assertDatabaseHas('aaud_auth_access_operations', ['status' => 'confirmed', 'attempts' => 2]);
    }

    public function test_version_conflict_uses_observed_remote_version_not_arbitrary_bumps(): void
    {
        $this->versionConflict = true;
        $this->link();
        $this->assertDatabaseHas('aaud_auth_access_operations', ['status' => 'failed', 'last_error_code' => 'version_conflict']);
        $this->versionConflict = false;
        $this->travel(3)->minutes();
        app(AccessDelivery::class)->recover();
        $this->assertSame([1, 8], array_column($this->sent, 'version'));
        $this->assertDatabaseHas('aaud_auth_access_operations', ['status' => 'cancelled', 'resolved_remote_version' => 7]);
        $this->assertDatabaseHas('aaud_auth_access_operations', ['status' => 'confirmed', 'version' => 8]);
    }

    public function test_historical_receipt_is_retained_and_not_reported_current_until_get_reconciliation(): void
    {
        $this->historicalReceipt = true;
        $this->link();
        $this->assertDatabaseHas('aaud_auth_access_operations', ['status' => 'acknowledged']);
        $this->assertFalse(app(AccessOutbox::class)->status((string) $this->user->id)['currently_synchronized']);
        $this->historicalReceipt = false;
        $this->travel(3)->minutes();
        app(AccessDelivery::class)->recover();
        $old = DB::table('aaud_auth_access_operations')->orderBy('id')->first();
        $this->assertSame('cancelled', $old->status);
        $this->assertTrue(json_decode($old->receipt, true)['historical']);
        $this->assertSame(3, $this->remote['subject-A']['version']);
        $this->assertTrue(app(AccessOutbox::class)->status((string) $this->user->id)['currently_synchronized']);
    }

    public function test_permanent_catalog_failure_requires_attention_and_never_publishes_unknown_role(): void
    {
        $this->link();
        $this->catalogRejected = true;
        $role = Role::create(['name' => 'catalog_rejected', 'guard_name' => 'web', 'is_active' => true]);
        $this->assertDatabaseHas('aaud_auth_catalog_operations', ['status' => 'failed', 'last_error_code' => 'forbidden']);
        $this->user->syncRoles($role);
        $this->assertSame(['operador'], $this->remote['subject-A']['roles']);
        $this->assertDatabaseHas('aaud_auth_access_targets', ['delivery_error' => 'needs_review']);
    }

    public function test_link_delivers_after_commit_and_confirms_real_state(): void
    {
        $this->link();
        $this->assertSame(['operador'], $this->remote['subject-A']['roles']);
        $this->assertSame(1, $this->remote['subject-A']['version']);
        $this->assertDatabaseHas('aaud_auth_access_operations', ['status' => 'confirmed', 'version' => 1]);
        $this->assertTrue(app(AccessOutbox::class)->status((string) $this->user->id)['currently_synchronized']);
    }

    public function test_role_activation_and_deactivation_preserve_order_and_local_password(): void
    {
        $this->link();
        $password = $this->user->fresh()->password;
        $this->user->syncRoles('consulta');
        $this->assertSame(['consulta'], $this->remote['subject-A']['roles']);
        $this->user->update(['is_active' => false]);
        $this->assertFalse($this->remote['subject-A']['is_active']);
        $this->assertSame([], $this->remote['subject-A']['roles']);
        $this->user->update(['is_active' => true]);
        $this->assertSame(['consulta'], $this->remote['subject-A']['roles']);
        $this->assertSame([1, 2, 3, 4], array_column($this->sent, 'version'));
        $this->assertSame($password, $this->user->fresh()->password);
    }

    public function test_historical_links_and_local_login_block_do_not_enroll_automatically(): void
    {
        $this->storedLink();
        $this->user->update(['is_login_blocked' => true, 'login_attempts' => 5]);
        app(AccessDelivery::class)->recover();
        $this->assertDatabaseCount('aaud_auth_access_targets', 0);
        Http::assertNothingSent();
    }

    public function test_rollback_discards_desired_state_and_never_delivers(): void
    {
        $this->storedLink();
        try {
            AdministrativeTransaction::run(function () {
                $this->user->syncRoles('consulta');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame('operador', $this->user->fresh()->roles->sole()->name);
        $this->assertDatabaseCount('aaud_auth_access_targets', 0);
        Http::assertNothingSent();
    }

    public function test_network_loss_preserves_operation_and_reconciles_without_new_uuid_or_duplicate_put(): void
    {
        $this->lostResponse = true;
        $this->link();
        $row = DB::table('aaud_auth_access_operations')->sole();
        $this->assertSame('uncertain', $row->status);
        $this->lostResponse = false;
        $this->travel(3)->minutes();
        app(AccessDelivery::class)->recover();
        $this->assertDatabaseCount('aaud_auth_access_operations', 1);
        $this->assertSame($row->operation_id, DB::table('aaud_auth_access_operations')->value('operation_id'));
        $this->assertSame('cancelled', DB::table('aaud_auth_access_operations')->value('status'));
        $this->assertCount(1, $this->sent);
        $this->assertTrue(app(AccessOutbox::class)->status((string) $this->user->id)['currently_synchronized']);
    }

    public function test_offline_before_version_reservation_is_durably_recovered(): void
    {
        $this->storedLink();
        $this->offline = true;
        $this->user->syncRoles('consulta');
        $this->assertDatabaseCount('aaud_auth_access_operations', 0);
        $this->assertDatabaseHas('aaud_auth_access_targets', ['delivery_attempts' => 1]);
        $this->offline = false;
        $this->travel(3)->minutes();
        app(AccessDelivery::class)->recover();
        $this->assertSame(['consulta'], $this->remote['subject-A']['roles']);
    }

    public function test_permanent_rejection_is_visible_and_not_retried_by_scheduler(): void
    {
        $this->reject = true;
        $this->link();
        $this->assertDatabaseHas('aaud_auth_access_operations', ['status' => 'failed']);
        $this->assertStringContainsString('requiere atención', app(AccessDelivery::class)->message([(string) $this->user->id]));
        $before = count($this->sent);
        $this->travel(1)->hour();
        app(AccessDelivery::class)->recover();
        $this->assertCount($before, $this->sent);
    }

    public function test_new_role_waits_for_catalog_confirmation(): void
    {
        $this->link();
        $this->catalogOffline = true;
        $role = Role::create(['name' => 'nuevo_piloto', 'guard_name' => 'web', 'is_active' => true]);
        $this->user->syncRoles($role);
        $this->assertSame(['operador'], $this->remote['subject-A']['roles']);
        $this->assertDatabaseHas('aaud_auth_access_targets', ['delivery_error' => 'catalog_pending']);
        $this->catalogOffline = false;
        $this->travel(3)->minutes();
        app(AccessDelivery::class)->recover();
        $this->assertSame(['nuevo_piloto'], $this->remote['subject-A']['roles']);
    }

    public function test_unlink_keeps_durable_revocation_and_replacement_waits_for_it(): void
    {
        $this->link();
        $identity = $this->user->ssoIdentity()->sole();
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->actor);
        $this->lostResponse = true;
        app(UserSsoIdentityManager::class)->unlink($this->user, $this->actor, $identity->id, $request);
        $this->assertFalse($this->user->ssoIdentity()->exists());
        $this->storedLink('subject-B');
        AdministrativeTransaction::run(fn () => AdministrativeTransaction::accessChanged($this->user, actor: $this->actor));
        $this->assertArrayNotHasKey('subject-B', $this->remote);
        $this->lostResponse = false;
        $this->travel(3)->minutes();
        app(AccessDelivery::class)->recover();
        $this->assertFalse($this->remote['subject-A']['is_active']);
        $this->assertTrue($this->remote['subject-B']['is_active']);
    }
}
