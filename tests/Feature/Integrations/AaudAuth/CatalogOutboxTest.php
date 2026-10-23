<?php

namespace Tests\Feature\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Exceptions\CatalogPersistenceException;
use App\Integrations\AaudAuth\Exceptions\InvalidRoleCatalogException;
use App\Integrations\AaudAuth\Exceptions\TechnicalAuthenticationException;
use App\Integrations\AaudAuth\Http\RoleCatalogResult;
use App\Integrations\AaudAuth\Roles\CatalogOperation;
use App\Integrations\AaudAuth\Roles\CatalogOutbox;
use App\Integrations\AaudAuth\Roles\RoleCatalogBuilder;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CatalogOutboxTest extends TestCase
{
    use RefreshDatabase;

    private CatalogOutbox $outbox;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(fn () => throw new RuntimeException('Outbox persistence must never call HTTP'));
        config(['aaud-auth.enabled' => false]);
        $this->seed(MasterDataSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        // Other existing suites truncate all data, including migration-owned singleton rows.
        DB::table('aaud_auth_catalog_states')->insertOrIgnore([
            'application_code' => 'pazysalvo', 'resource' => 'roles', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->outbox = app(CatalogOutbox::class);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function initialize(int $version = 40): void
    {
        $this->outbox->recordRemoteObservation($version, 'TEST-VERIFICATION-1', $this->admin);
    }

    private function receipt(CatalogOperation $operation, string $status = 'applied', ?int $current = null): RoleCatalogResult
    {
        return new RoleCatalogResult($status, $operation->version, $operation->operationId,
            $current ?? $operation->version, ($current ?? $operation->version) > $operation->version,
            array_map(fn ($role) => [...$role, 'name' => trim($role['name']), 'description' => null], $operation->payload()['roles']));
    }

    private function reject(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a catalog persistence rejection');
        } catch (CatalogPersistenceException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    public function test_unknown_remote_version_blocks_preparation_without_reserving_anything(): void
    {
        $state = $this->outbox->status()['state'];
        $this->assertNull($state->last_remote_version);
        $this->assertNull($state->last_reserved_version);
        $this->assertNull($state->remote_verified_at);
        $this->reject('unverified', fn () => $this->outbox->prepare());
        $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
        $this->assertFalse($this->outbox->isCurrentlySynchronized());
    }

    public function test_records_explicit_remote_observation_with_administrator_approval_and_audit(): void
    {
        $this->initialize(78);
        $state = $this->outbox->status()['state'];
        $this->assertSame(78, $state->last_remote_version);
        $this->assertNull($state->last_reserved_version);
        $this->assertNotNull($state->remote_verified_at);
        $this->assertNull($state->last_confirmed_at);
        $audit = DB::table('audit_logs')->where('event', 'aaud_auth.catalog.remote_observed')->first();
        $metadata = json_decode($audit->metadata, true);
        $this->assertSame(78, $metadata['observed_version']);
        $this->assertSame($this->admin->id, $metadata['approved_by_user_id']);
        $this->assertSame('TEST-VERIFICATION-1', $metadata['evidence_reference']);
    }

    public function test_observations_reject_invalid_regressive_unapproved_and_unresolved_changes(): void
    {
        $this->reject('version', fn () => $this->initialize(-1));
        $this->reject('version', fn () => $this->initialize(PHP_INT_MAX));
        $user = User::factory()->create();
        $this->reject('approval', fn () => $this->outbox->recordRemoteObservation(0, 'TICKET-1', $user));
        $this->reject('approval', fn () => $this->outbox->recordRemoteObservation(0, 'https://user:secret@example.test', $this->admin));
        $this->initialize();
        $this->reject('version', fn () => $this->initialize(39));
        $this->outbox->prepare();
        $this->reject('unresolved', fn () => $this->initialize(100));
        $this->assertSame(40, $this->outbox->status()['state']->last_remote_version);
    }

    public function test_prepares_complete_snapshot_with_custom_inactive_roles_and_no_catalog_writes(): void
    {
        $this->initialize();
        Role::create(['name' => 'Comercial', 'code' => 'comercial', 'guard_name' => 'web']);
        Role::create(['name' => 'Archivo', 'code' => 'archivo', 'guard_name' => 'web', 'is_active' => false]);
        Role::create(['name' => 'Other', 'guard_name' => 'api']);
        $before = DB::table('roles')->orderBy('id')->get()->toJson();
        $expected = app(RoleCatalogBuilder::class)->build();
        $operation = $this->outbox->prepare();
        $this->assertSame('pending', $operation->status);
        $this->assertSame(0, $operation->attempts);
        $this->assertSame(41, $operation->version);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $operation->payloadHash);
        $this->assertSame(['version' => 41, 'operation_id' => $operation->operationId, 'roles' => $expected], $operation->payload());
        $this->assertCount(7, $operation->payload()['roles']);
        $this->assertContains(['code' => 'archivo', 'name' => 'Archivo', 'active' => false], $operation->payload()['roles']);
        $this->assertSame($before, DB::table('roles')->orderBy('id')->get()->toJson());
        $this->assertSame($operation->payload(), $this->outbox->get($operation->id)->payload());
        $this->assertSame(41, $this->outbox->status()['state']->last_reserved_version);
    }

    public function test_second_unresolved_snapshot_is_rejected_without_consuming_a_version(): void
    {
        $this->initialize();
        $one = $this->outbox->prepare();
        $this->reject('unresolved', fn () => $this->outbox->prepare());
        $this->assertDatabaseCount('aaud_auth_catalog_operations', 1);
        $this->assertSame($one->version, $this->outbox->status()['state']->last_reserved_version);
    }

    public function test_empty_catalog_rolls_back_version_reservation(): void
    {
        $this->initialize();
        DB::table('roles')->where('guard_name', 'web')->update(['guard_name' => 'api']);
        try {
            $this->outbox->prepare();
            $this->fail('Empty catalog must be rejected');
        } catch (InvalidRoleCatalogException) {
            $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
            $this->assertNull($this->outbox->status()['state']->last_reserved_version);
        }
    }

    public function test_monotonic_versions_after_confirmation_and_new_remote_observation(): void
    {
        $this->initialize(0); // zero is permitted only as an explicitly verified observation
        $one = $this->outbox->prepare();
        $this->assertSame(1, $one->version);
        $claim = $this->outbox->claim($one->id);
        $this->outbox->confirm($one->id, $claim->claimId, $this->receipt($one));
        $this->initialize(100);
        $two = $this->outbox->prepare();
        $this->assertSame(101, $two->version);
        $this->assertNotSame($one->operationId, $two->operationId);
    }

    public function test_exhausted_version_range_does_not_create_an_operation(): void
    {
        $this->initialize(9007199254740991);
        $this->reject('version', fn () => $this->outbox->prepare());
        $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
    }

    public function test_local_transaction_rollback_reverts_operation_version_and_success_audit(): void
    {
        $this->initialize();
        $before = DB::table('audit_logs')->count();
        try {
            DB::transaction(function () {
                $this->outbox->prepare();
                throw new RuntimeException('Local administrative failure');
            });
        } catch (RuntimeException) {
            $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
        }
        $this->assertNull($this->outbox->status()['state']->last_reserved_version);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_successful_transaction_retains_operation_after_reconstructing_service(): void
    {
        $this->initialize();
        $operation = DB::transaction(fn () => $this->outbox->prepare());
        $reloaded = app(CatalogOutbox::class)->get($operation->id);
        $this->assertSame($operation->payload(), $reloaded->payload());
        $this->assertSame($operation->payloadHash, $reloaded->payloadHash);
        $this->assertSame('pending', $reloaded->status);
    }

    public function test_audit_failure_rolls_back_preparation_even_if_outer_transaction_continues(): void
    {
        $this->initialize();
        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $outbox = new CatalogOutbox(app(RoleCatalogBuilder::class), $audit);
        DB::transaction(function () use ($outbox) {
            try {
                $outbox->prepare();
            } catch (RuntimeException) { /* caller deliberately continues */
            }
            $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
            $this->assertNull($this->outbox->status()['state']->last_reserved_version);
        });
    }

    public function test_confirmation_distinguishes_current_local_state_from_an_acknowledged_operation(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $claim = $this->outbox->claim($operation->id);
        $this->assertSame('processing', $claim->status);
        $this->assertSame(1, $claim->attempts);
        $confirmed = $this->outbox->confirm($operation->id, $claim->claimId, $this->receipt($operation));
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertTrue($this->outbox->isCurrentlySynchronized());
        $this->assertNotNull($this->outbox->status()['state']->last_confirmed_at);
        Role::create(['name' => 'Changed local catalog', 'guard_name' => 'web']);
        $this->assertFalse($this->outbox->isCurrentlySynchronized());
        $this->assertSame('confirmed', $this->outbox->get($operation->id)->status);
        $this->outbox->prepare();
        $this->assertFalse($this->outbox->isCurrentlySynchronized());
    }

    public function test_historical_duplicate_confirms_operation_but_never_current_catalog(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $claim = $this->outbox->claim($operation->id);
        $this->outbox->confirm($operation->id, $claim->claimId, $this->receipt($operation, 'duplicate', 50));
        $this->assertSame('confirmed', $this->outbox->get($operation->id)->status);
        $this->assertSame(50, $this->outbox->status()['state']->last_remote_version);
        $this->assertFalse($this->outbox->isCurrentlySynchronized());
        $this->assertSame(51, $this->outbox->prepare()->version);
    }

    public static function wrongReceipts(): array
    {
        return [['version'], ['uuid'], ['snapshot'], ['missing_snapshot']];
    }

    #[DataProvider('wrongReceipts')]
    public function test_mismatched_receipts_do_not_confirm_anything(string $mismatch): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $claim = $this->outbox->claim($operation->id);
        $receipt = $this->receipt($operation);
        $roles = $receipt->roles;
        if ($mismatch === 'snapshot') {
            $roles[0]['name'] = 'Different';
        }
        $bad = new RoleCatalogResult('applied', $mismatch === 'version' ? 42 : 41,
            $mismatch === 'uuid' ? 'a0000000-0000-4000-8000-000000000001' : $operation->operationId,
            $mismatch === 'version' ? 42 : 41, false, $mismatch === 'missing_snapshot' ? null : $roles);
        $this->reject('receipt', fn () => $this->outbox->confirm($operation->id, $claim->claimId, $bad));
        $this->assertSame('processing', $this->outbox->get($operation->id)->status);
        $this->assertNull($this->outbox->status()['state']->last_confirmed_at);
    }

    public static function deliveryFailures(): array
    {
        return [['timeout', 'uncertain'], ['connection', 'uncertain'], ['temporary', 'uncertain'],
            ['invalid_response', 'uncertain'], ['unexpected_response', 'uncertain'], ['rate_limited', 'retryable'],
            ['unauthorized', 'failed'], ['forbidden', 'failed'], ['version_conflict', 'failed'],
            ['idempotency_conflict', 'failed'], ['invalid_request', 'failed'], ['configuration', 'failed']];
    }

    #[DataProvider('deliveryFailures')]
    public function test_delivery_outcomes_are_persisted_without_false_confirmation(string $reason, string $expected): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $claim = $this->outbox->claim($operation->id);
        $changed = $this->outbox->recordFailure($operation->id, $claim->claimId,
            new AaudAuthRequestException($reason), $expected === 'failed' ? null : now()->addMinute());
        $this->assertSame($expected, $changed->status);
        $this->assertSame($reason, $changed->lastErrorCode);
        $this->assertSame($operation->payload(), $changed->payload());
        $this->assertNull($changed->claimId);
        $this->assertNull($this->outbox->status()['state']->last_confirmed_at);
        $this->reject('unresolved', fn () => $this->outbox->prepare());
    }

    public function test_retry_reuses_exact_identity_payload_and_hash_even_after_local_role_changes(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $claim = $this->outbox->claim($operation->id);
        $this->outbox->recordFailure($operation->id, $claim->claimId, new AaudAuthRequestException('timeout'), now()->addSeconds(5));
        Role::create(['name' => 'New role', 'guard_name' => 'web']);
        $this->reject('transition', fn () => $this->outbox->claim($operation->id));
        $this->travel(6)->seconds();
        $retry = $this->outbox->claim($operation->id);
        $this->assertSame(2, $retry->attempts);
        $this->assertNotSame($claim->claimId, $retry->claimId);
        $this->assertSame($operation->payload(), $retry->payload());
        $this->assertSame($operation->payloadHash, $retry->payloadHash);
        $this->reject('claim', fn () => $this->outbox->confirm($operation->id, $claim->claimId, $this->receipt($operation)));
        $this->outbox->confirm($operation->id, $retry->claimId, $this->receipt($operation, 'duplicate'));
        $this->assertFalse($this->outbox->isCurrentlySynchronized());
    }

    public function test_crashed_processing_claim_becomes_uncertain_and_old_claim_cannot_confirm(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $claim = $this->outbox->claim($operation->id, 1);
        $this->reject('transition', fn () => $this->outbox->recoverExpiredClaim($operation->id));
        $this->reject('transition', fn () => $this->outbox->claim($operation->id));
        usleep(1100000); // Exercise actual PostgreSQL lease expiry, not just Carbon's test clock.
        $this->reject('claim', fn () => $this->outbox->confirm($operation->id, $claim->claimId, $this->receipt($operation)));
        $recovered = app(CatalogOutbox::class)->recoverExpiredClaim($operation->id);
        $this->assertSame('uncertain', $recovered->status);
        $this->assertSame('lease_expired', $recovered->lastErrorCode);
        $this->assertSame($operation->payload(), $recovered->payload());
        $retry = $this->outbox->claim($operation->id);
        $this->assertSame(2, $retry->attempts);
        $this->reject('claim', fn () => $this->outbox->recordFailure($operation->id, $claim->claimId, new AaudAuthRequestException('forbidden')));
        $this->outbox->confirm($operation->id, $retry->claimId, $this->receipt($operation, 'duplicate'));
        $this->assertTrue($this->outbox->isCurrentlySynchronized());
    }

    public function test_failure_codes_are_sanitized_and_oauth_failures_are_distinguished(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $claim = $this->outbox->claim($operation->id);
        $this->outbox->recordFailure($operation->id, $claim->claimId,
            new TechnicalAuthenticationException('temporary_provider_error'), now()->addSecond());
        $this->assertSame('retryable', $this->outbox->get($operation->id)->status);
        $this->travel(2)->seconds();
        $claim = $this->outbox->claim($operation->id);
        $changed = $this->outbox->recordFailure($operation->id, $claim->claimId, new TechnicalAuthenticationException('private-token-secret'));
        $this->assertSame('unexpected_response', $changed->lastErrorCode);
        $this->assertSame('failed', $changed->status);
        $this->assertStringNotContainsString('private-token-secret', DB::table('audit_logs')->get()->toJson());
        $this->assertStringNotContainsString('private-token-secret', json_encode($this->outbox->status()));
    }

    public function test_invalid_transitions_and_retry_dates_are_rejected(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $this->reject('claim', fn () => $this->outbox->confirm($operation->id, 'invalid', $this->receipt($operation)));
        $this->reject('transition', fn () => $this->outbox->claim($operation->id, 0));
        $this->reject('transition', fn () => $this->outbox->claim($operation->id, 3601));
        $claim = $this->outbox->claim($operation->id);
        $this->reject('transition', fn () => $this->outbox->recordFailure($operation->id, $claim->claimId, new AaudAuthRequestException('timeout')));
        $this->reject('transition', fn () => $this->outbox->recordFailure($operation->id, $claim->claimId, new AaudAuthRequestException('timeout'), now()->subSecond()));
        $this->reject('transition', fn () => $this->outbox->recordFailure($operation->id, $claim->claimId, new AaudAuthRequestException('forbidden'), now()->addMinute()));
        $this->outbox->confirm($operation->id, $claim->claimId, $this->receipt($operation));
        $this->reject('transition', fn () => $this->outbox->claim($operation->id));
        $this->reject('claim', fn () => $this->outbox->confirm($operation->id, $claim->claimId, $this->receipt($operation)));
    }

    public function test_outbox_migration_rolls_back_and_reapplies_without_altering_roles_or_users(): void
    {
        $roles = DB::table('roles')->orderBy('id')->get()->toJson();
        $users = DB::table('users')->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_10_20_000001_create_aaud_auth_catalog_outbox.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('aaud_auth_catalog_operations'));
        $this->assertFalse(Schema::hasTable('aaud_auth_catalog_states'));
        $migration->up();
        $this->assertNull($this->outbox->status()['state']->last_remote_version);
        $this->assertSame($roles, DB::table('roles')->orderBy('id')->get()->toJson());
        $this->assertSame($users, DB::table('users')->orderBy('id')->get()->toJson());
    }

    public static function immutableChanges(): array
    {
        return [['version', 99], ['operation_id', 'a0000000-0000-4000-8000-000000000001'],
            ['payload_hash', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
            ['application_code', 'another-app'], ['resource', 'users'], ['created_at', '2020-01-01 00:00:00+00']];
    }

    #[DataProvider('immutableChanges')]
    public function test_postgresql_rejects_mutation_of_persisted_identity(string $column, mixed $value): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        try {
            DB::transaction(fn () => DB::table('aaud_auth_catalog_operations')->where('id', $operation->id)->update([$column => $value]));
            $this->fail('PostgreSQL must protect immutable operation data');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        $this->assertSame($operation->payload(), $this->outbox->get($operation->id)->payload());
        $this->assertSame($operation->payloadHash, $this->outbox->get($operation->id)->payloadHash);
    }

    public function test_postgresql_rejects_payload_mutation_deletion_and_skipped_states(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $changed = $operation->payload();
        $changed['roles'][0]['name'] = 'Tampered';
        foreach ([
            fn () => DB::table('aaud_auth_catalog_operations')->where('id', $operation->id)->update(['payload' => json_encode($changed)]),
            fn () => DB::table('aaud_auth_catalog_operations')->where('id', $operation->id)->delete(),
            fn () => DB::table('aaud_auth_catalog_operations')->where('id', $operation->id)->update(['status' => 'failed']),
            fn () => DB::table('aaud_auth_catalog_operations')->where('id', $operation->id)->update(['attempts' => 1]),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Illegal database mutation must be rejected');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
        $this->assertSame('pending', $this->outbox->get($operation->id)->status);
    }

    public function test_integrity_verification_detects_corruption_even_if_a_privileged_writer_bypassed_the_trigger(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $changed = $operation->payload();
        $changed['roles'][0]['name'] = 'Tampered';
        // Deliberate corruption solely inside this isolated test transaction; never a runtime path.
        DB::statement('ALTER TABLE aaud_auth_catalog_operations DISABLE TRIGGER aaud_auth_catalog_operation_guard');
        try {
            DB::table('aaud_auth_catalog_operations')->where('id', $operation->id)->update(['payload' => json_encode($changed)]);
            $this->reject('integrity', fn () => $this->outbox->get($operation->id));
        } finally {
            DB::statement('ALTER TABLE aaud_auth_catalog_operations ENABLE TRIGGER aaud_auth_catalog_operation_guard');
        }
    }

    public function test_definitive_failure_can_be_cancelled_with_verified_observation_approval_and_audit(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $claim = $this->outbox->claim($operation->id);
        $this->outbox->recordFailure($operation->id, $claim->claimId, new AaudAuthRequestException('invalid_request'));
        $cancelled = $this->outbox->cancelAfterReview($operation->id, 40, 'MANUAL-RECONCILIATION-1', $this->admin);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($operation->payload(), $cancelled->payload());
        $this->assertSame($operation->payloadHash, $cancelled->payloadHash);
        $row = DB::table('aaud_auth_catalog_operations')->find($operation->id);
        $this->assertSame($this->admin->id, $row->resolution_approved_by);
        $this->assertSame(40, $row->resolved_remote_version);
        $this->assertNotNull($row->resolved_at);
        $this->assertNull($row->confirmed_at);
        $audit = DB::table('audit_logs')->where('event', 'aaud_auth.catalog.cancelled')->first();
        $this->assertSame('cancelled', $audit->result);
        $this->assertStringContainsString('MANUAL-RECONCILIATION-1', $audit->metadata);
        $this->assertSame(42, $this->outbox->prepare()->version);
        $this->reject('transition', fn () => $this->outbox->claim($operation->id));
    }

    public function test_uncertainty_history_survives_retry_and_requires_a_remote_fence_before_cancellation(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $claim = $this->outbox->claim($operation->id);
        $this->outbox->recordFailure($operation->id, $claim->claimId, new AaudAuthRequestException('timeout'), now()->addSecond());
        $this->reject('reconciliation', fn () => $this->outbox->cancelAfterReview($operation->id, 40, 'RECONCILE-1', $this->admin));
        $this->travel(2)->seconds();
        $retry = $this->outbox->claim($operation->id);
        $failed = $this->outbox->recordFailure($operation->id, $retry->claimId, new AaudAuthRequestException('forbidden'));
        $this->assertTrue($failed->hasUncertainAttempt);
        $this->reject('reconciliation', fn () => $this->outbox->cancelAfterReview($operation->id, 40, 'RECONCILE-1', $this->admin));
        $this->assertSame('failed', $this->outbox->get($operation->id)->status);
        $cancelled = $this->outbox->cancelAfterReview($operation->id, 50, 'RECONCILE-REMOTE-V50', $this->admin);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertTrue($cancelled->hasUncertainAttempt);
        $this->assertSame(51, $this->outbox->prepare()->version);
    }

    public function test_cancel_requires_active_administrator_valid_reference_and_non_regressive_remote_version(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $this->reject('transition', fn () => $this->outbox->cancelAfterReview($operation->id, 40, 'RECONCILE-1', $this->admin));
        $claim = $this->outbox->claim($operation->id);
        $this->outbox->recordFailure($operation->id, $claim->claimId, new AaudAuthRequestException('forbidden'));
        $this->reject('approval', fn () => $this->outbox->cancelAfterReview($operation->id, 40, 'RECONCILE-1', User::factory()->create()));
        $this->reject('approval', fn () => $this->outbox->cancelAfterReview($operation->id, 40, 'http://credentials@example.test', $this->admin));
        $this->reject('version', fn () => $this->outbox->cancelAfterReview($operation->id, 39, 'RECONCILE-1', $this->admin));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'aaud_auth.catalog.cancelled']);
        $this->reject('unresolved', fn () => $this->outbox->prepare());
    }

    public function test_cancellation_rolls_back_if_audit_is_unavailable(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $claim = $this->outbox->claim($operation->id);
        $this->outbox->recordFailure($operation->id, $claim->claimId, new AaudAuthRequestException('forbidden'));
        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $outbox = new CatalogOutbox(app(RoleCatalogBuilder::class), $audit);
        try {
            $outbox->cancelAfterReview($operation->id, 50, 'RECONCILE-1', $this->admin);
            $this->fail('Audit failure must revert cancellation');
        } catch (RuntimeException) {
            $this->assertSame('failed', $this->outbox->get($operation->id)->status);
            $this->assertSame(40, $this->outbox->status()['state']->last_remote_version);
        }
    }

    public function test_changes_during_pending_delivery_are_detected_again_after_confirmation_and_restart(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $this->assertTrue($this->outbox->inspectLocalChanges()['snapshot_matches_local']);
        Role::create(['name' => 'Comercial', 'code' => 'comercial', 'guard_name' => 'web']);
        $signals = app(CatalogOutbox::class)->inspectLocalChanges();
        $this->assertFalse($signals['snapshot_matches_local']);
        $this->assertFalse($signals['needs_preparation']);
        $this->assertTrue($signals['blocked_by_unresolved']);
        $this->assertSame($operation->payload(), $this->outbox->get($operation->id)->payload());
        $claim = $this->outbox->claim($operation->id);
        $this->outbox->confirm($operation->id, $claim->claimId, $this->receipt($operation));
        $signals = app(CatalogOutbox::class)->inspectLocalChanges();
        $this->assertFalse($signals['currently_synchronized']);
        $this->assertTrue($signals['needs_preparation']);
        $this->assertFalse($signals['blocked_by_unresolved']);
        $new = $this->outbox->prepare();
        $this->assertSame(42, $new->version);
        $this->assertContains(['code' => 'comercial', 'name' => 'Comercial', 'active' => true], $new->payload()['roles']);
        $this->assertTrue($this->outbox->inspectLocalChanges()['snapshot_matches_local']);
    }

    public function test_postgresql_forbids_replacing_or_extending_an_active_claim(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $claim = $this->outbox->claim($operation->id);
        foreach ([['claim_id' => 'a0000000-0000-4000-8000-000000000001'], ['lease_expires_at' => now()->addHour()],
            ['claim_id' => null, 'lease_expires_at' => null], ['has_uncertain_attempt' => true]] as $changes) {
            try {
                DB::transaction(fn () => DB::table('aaud_auth_catalog_operations')->where('id', $operation->id)->update($changes));
                $this->fail('Claim fencing must be enforced in PostgreSQL');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
        $this->assertSame($claim->claimId, $this->outbox->get($operation->id)->claimId);
        $this->assertSame($claim->leaseExpiresAt->toIso8601String(), $this->outbox->get($operation->id)->leaseExpiresAt->toIso8601String());
    }

    public function test_uncertain_operation_can_be_cancelled_only_after_verified_remote_fencing(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $claim = $this->outbox->claim($operation->id);
        $this->outbox->recordFailure($operation->id, $claim->claimId, new AaudAuthRequestException('timeout'), now()->addMinute());
        $cancelled = $this->outbox->cancelAfterReview($operation->id, 41, 'REMOTE-VERIFIED-V41', $this->admin);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNull($cancelled->nextAttemptAt);
        $this->assertTrue($cancelled->hasUncertainAttempt);
        $this->assertFalse($this->outbox->isCurrentlySynchronized());
        $this->assertSame(42, $this->outbox->prepare()->version);
        $this->reject('claim', fn () => $this->outbox->confirm($operation->id, $claim->claimId, $this->receipt($operation)));
    }

    public function test_postgresql_also_rejects_unreconciled_cancellation_and_erasing_uncertainty(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $claim = $this->outbox->claim($operation->id);
        $this->outbox->recordFailure($operation->id, $claim->claimId, new AaudAuthRequestException('timeout'), now()->addMinute());
        foreach ([['has_uncertain_attempt' => false], [
            'status' => 'cancelled', 'resolved_at' => now(), 'resolved_remote_version' => 40,
            'resolution_reference' => 'UNSAFE-RECONCILIATION', 'resolution_approved_by' => $this->admin->id,
        ]] as $changes) {
            try {
                DB::transaction(fn () => DB::table('aaud_auth_catalog_operations')->where('id', $operation->id)->update($changes));
                $this->fail('Uncertain operations must not be silently discarded');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
        $this->assertSame('uncertain', $this->outbox->get($operation->id)->status);
        $this->assertTrue($this->outbox->get($operation->id)->hasUncertainAttempt);
    }

    public function test_database_rejects_confirmation_after_the_lease_really_expired(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $this->outbox->claim($operation->id, 1);
        usleep(1100000);
        try {
            DB::transaction(fn () => DB::table('aaud_auth_catalog_operations')->where('id', $operation->id)->update([
                'status' => 'confirmed', 'confirmed_at' => now(), 'receipt_status' => 'applied',
                'receipt_current_version' => $operation->version, 'claim_id' => null, 'lease_expires_at' => null, 'updated_at' => now(),
            ]));
            $this->fail('Expired database lease cannot confirm');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        $this->assertSame('processing', $this->outbox->get($operation->id)->status);
    }

    public function test_migration_rollback_refuses_to_destroy_durable_operations(): void
    {
        $this->initialize();
        $operation = $this->outbox->prepare();
        $migration = require database_path('migrations/2026_10_20_000001_create_aaud_auth_catalog_outbox.php');
        try {
            $migration->down();
            $this->fail('Rollback must not destroy persisted snapshots');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('populated catalog outbox', $exception->getMessage());
        }
        $this->assertSame($operation->payload(), $this->outbox->get($operation->id)->payload());
        $this->assertSame(41, $this->outbox->status()['state']->last_reserved_version);
    }
}
