<?php

namespace Tests\Feature\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Access\AccessOperation;
use App\Integrations\AaudAuth\Access\AccessOutbox;
use App\Integrations\AaudAuth\Access\AccessSnapshot;
use App\Integrations\AaudAuth\Access\AccessSnapshotBuilder;
use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Exceptions\AccessPersistenceException;
use App\Integrations\AaudAuth\Http\UserAccessResult;
use App\Models\User;
use App\Models\UserSsoIdentity;
use App\Services\AuditLogger;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessOutboxTest extends TestCase
{
    use RefreshDatabase;

    private AccessOutbox $outbox;

    private User $actor;

    private User $user;

    private const ISSUER = 'https://keycloak.example.test/realms/aaud';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \LogicException('Persistence must never call HTTP'));
        config(['aaud-auth.issuer' => self::ISSUER]);
        $this->seed(MasterDataSeeder::class);
        $this->actor = User::factory()->create();
        $this->actor->assignRole('admin');
        $this->user = User::factory()->create();
        $this->user->assignRole('operador');
        UserSsoIdentity::create(['user_id' => $this->user->id, 'issuer' => self::ISSUER, 'subject' => 'subject-A']);
        $this->outbox = app(AccessOutbox::class);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function snapshot(): AccessSnapshot
    {
        return app(AccessSnapshotBuilder::class)->build($this->user);
    }

    private function observeAbsent(?AccessSnapshot $snapshot = null): void
    {
        $revocation = app(AccessSnapshotBuilder::class)->revocation(($snapshot ?? $this->snapshot())->payload());
        $this->outbox->recordRemoteObservation($revocation, 0, true, 'TEST-ABSENCE', $this->actor);
    }

    private function prepared(): AccessOperation
    {
        $this->observeAbsent();

        return $this->outbox->prepare($this->user, $this->actor);
    }

    private function receipt(array $payload, bool $historical = false): UserAccessResult
    {
        $snapshot = AccessSnapshot::fromArray(array_diff_key($payload, array_flip(['version', 'operation_id'])));
        $body = ['status' => $historical ? 'duplicate' : 'applied', 'data' => $payload + ['application_code' => 'pazysalvo', 'confirmed_at' => '2026-10-21T10:00:00-05:00'],
            'current_version' => $payload['version'], 'historical' => $historical];
        if ($historical) {
            $body['current_state'] = ['version' => $payload['version'], 'is_active' => false, 'roles' => [], 'catalog_version' => 1];
        }

        return UserAccessResult::fromReceipt($body, $snapshot, $payload['version'], $payload['operation_id']);
    }

    private function confirmed($op): void
    {
        $claim = $this->outbox->claim($op->id);
        $this->outbox->confirm($op->id, $claim->claimId, $this->receipt($op->payload()));
    }

    public function test_unknown_version_retains_desired_without_reserving_operation(): void
    {
        $this->assertNull($this->outbox->prepare($this->user, $this->actor));
        $this->assertDatabaseCount('aaud_auth_access_operations', 0);
        $this->assertTrue($this->outbox->status((string) $this->user->id)['needs_reconciliation']);
        $this->assertNull(DB::table('aaud_auth_access_states')->value('last_reserved_version'));
    }

    public function test_prepares_complete_immutable_active_snapshot_with_audit(): void
    {
        $op = $this->prepared();
        $this->assertSame('pending', $op->status);
        $this->assertSame(1, $op->payload()['version']);
        $this->assertSame(['operador'], $op->payload()['roles']);
        $this->assertSame($op->payload(), app(AccessOutbox::class)->get($op->id)->payload());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $op->payloadHash);
        $this->assertDatabaseHas('audit_logs', ['event' => 'aaud_auth.access.prepared']);
    }

    public function test_changes_during_pending_are_durable_and_versioned_after_confirmation(): void
    {
        $op = $this->prepared();
        $this->user->syncRoles(['consulta']);
        $this->assertNull($this->outbox->prepare($this->user, $this->actor));
        $this->user->syncRoles(['supervisor']);
        $this->assertNull($this->outbox->prepare($this->user, $this->actor));
        $this->assertSame(['operador'], $this->outbox->get($op->id)->payload()['roles']);
        $this->confirmed($op);
        $this->assertFalse($this->outbox->status((string) $this->user->id)['currently_synchronized']);
        $next = app(AccessOutbox::class)->prepareNext((string) $this->user->id, $this->actor);
        $this->assertSame(2, $next->payload()['version']);
        $this->assertSame(['supervisor'], $next->payload()['roles']);
        $this->assertNotSame($op->payload()['operation_id'], $next->payload()['operation_id']);
    }

    public function test_revocation_survives_deleted_user_and_identity(): void
    {
        $op = $this->prepared();
        $this->confirmed($op);
        $old = $this->snapshot()->payload();
        $this->user->ssoIdentity()->delete();
        $this->user->delete();
        $revocation = $this->outbox->prepareRevocation($old, $this->actor);
        $this->assertFalse($revocation->payload()['is_active']);
        $this->assertSame([], $revocation->payload()['roles']);
        $this->assertSame('subject-A', $revocation->payload()['subject']);
        $this->assertDatabaseCount('aaud_auth_access_operations', 2);
    }

    public function test_replacement_waits_for_revocation_even_if_desired_identity_changes_again(): void
    {
        $first = $this->prepared();
        $this->confirmed($first);
        $old = $this->snapshot()->payload();
        $this->user->ssoIdentity()->update(['subject' => 'subject-B']);
        $this->observeAbsent();
        $revoke = $this->outbox->prepareReplacement($old, $this->user, $this->actor);
        $this->assertSame('subject-A', $revoke->payload()['subject']);
        $this->assertFalse($revoke->payload()['is_active']);
        $this->user->ssoIdentity()->update(['subject' => 'subject-C']);
        $this->observeAbsent();
        $this->assertNull($this->outbox->prepare($this->user, $this->actor));
        $this->assertNull($this->outbox->prepareNext((string) $this->user->id, $this->actor));
        $this->confirmed($revoke);
        $new = $this->outbox->prepareNext((string) $this->user->id, $this->actor);
        $this->assertSame('subject-C', $new->payload()['subject']);
        $this->assertSame(1, $new->payload()['version']);
    }

    public function test_timeout_retry_preserves_payload_uuid_and_uncertainty(): void
    {
        $op = $this->prepared();
        $claim = $this->outbox->claim($op->id);
        $uncertain = $this->outbox->recordFailure($op->id, $claim->claimId, new AaudAuthRequestException('timeout'), 0);
        $this->assertSame('uncertain', $uncertain->status);
        $retry = $this->outbox->claim($op->id);
        $this->assertSame($op->payload(), $retry->payload());
        $this->assertNotSame($claim->claimId, $retry->claimId);
        $this->assertSame(2, $retry->attempts);
        $this->expectException(AccessPersistenceException::class);
        $this->outbox->confirm($op->id, $claim->claimId, $this->receipt($op->payload()));
    }

    public function test_claim_cannot_be_reclaimed_while_live(): void
    {
        $op = $this->prepared();
        $this->outbox->claim($op->id);
        $this->expectException(AccessPersistenceException::class);
        $this->outbox->claim($op->id);
    }

    public function test_expired_claim_recovers_and_stale_owner_cannot_confirm(): void
    {
        $op = $this->prepared();
        $claim = $this->outbox->claim($op->id, 1);
        usleep(1100000); // PostgreSQL clock, not only PHP's test clock.
        $this->assertSame('uncertain', $this->outbox->recoverExpiredClaim($op->id)->status);
        $this->outbox->claim($op->id);
        $this->expectException(AccessPersistenceException::class);
        $this->outbox->confirm($op->id, $claim->claimId, $this->receipt($op->payload()));
    }

    public function test_historical_duplicate_is_acknowledged_but_blocks_new_operation(): void
    {
        $op = $this->prepared();
        $claim = $this->outbox->claim($op->id);
        $ack = $this->outbox->confirm($op->id, $claim->claimId, $this->receipt($op->payload(), true));
        $this->assertSame('acknowledged', $ack->status);
        $this->assertTrue($ack->receipt['historical']);
        $this->assertNull($this->outbox->prepareNext((string) $this->user->id, $this->actor));
        $this->assertFalse($this->outbox->status((string) $this->user->id)['currently_synchronized']);
        $observed = app(AccessSnapshotBuilder::class)->revocation($op->payload());
        $this->outbox->reconcile($op->id, $observed, 1, false, 'RECONCILED', $this->actor);
        $this->assertSame(2, $this->outbox->prepareNext((string) $this->user->id, $this->actor)->payload()['version']);
    }

    public function test_uncertain_operation_cannot_be_discarded_using_unfenced_absence(): void
    {
        $op = $this->prepared();
        $claim = $this->outbox->claim($op->id);
        $this->outbox->recordFailure($op->id, $claim->claimId, new AaudAuthRequestException('timeout'), 0);
        $this->expectException(AccessPersistenceException::class);
        $this->outbox->reconcile($op->id, app(AccessSnapshotBuilder::class)->revocation($op->payload()), 0, true, 'UNSAFE', $this->actor);
    }

    public function test_receipt_for_another_operation_cannot_confirm(): void
    {
        $op = $this->prepared();
        $claim = $this->outbox->claim($op->id);
        $bad = $op->payload();
        $bad['operation_id'] = (string) Str::uuid();
        $this->expectException(AccessPersistenceException::class);
        $this->outbox->confirm($op->id, $claim->claimId, $this->receipt($bad));
    }

    public function test_outer_rollback_reverts_desired_operation_version_and_audit(): void
    {
        $this->observeAbsent();
        try {
            DB::transaction(function () {
                $this->outbox->prepare($this->user, $this->actor);
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('aaud_auth_access_operations', 0);
        $this->assertDatabaseCount('aaud_auth_access_targets', 0);
        $this->assertNull(DB::table('aaud_auth_access_states')->value('last_reserved_version'));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'aaud_auth.access.prepared']);
    }

    public function test_audit_failure_rolls_back_even_inside_caught_outer_transaction(): void
    {
        $this->observeAbsent();
        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->andThrow(new \RuntimeException('audit unavailable'));
        $outbox = new AccessOutbox(app(AccessSnapshotBuilder::class), $audit);
        DB::transaction(function () use ($outbox) {
            try {
                $outbox->prepare($this->user, $this->actor);
            } catch (\RuntimeException) {
            }
            $this->assertDatabaseCount('aaud_auth_access_operations', 0);
            $this->assertDatabaseCount('aaud_auth_access_targets', 0);
        });
    }

    public function test_database_prevents_payload_changes_and_deletion(): void
    {
        $op = $this->prepared();
        foreach (['update', 'delete'] as $action) {
            try {
                DB::transaction(fn () => $action === 'delete' ? DB::table('aaud_auth_access_operations')->where('id', $op->id)->delete()
                    : DB::table('aaud_auth_access_operations')->where('id', $op->id)->update(['payload' => '{}']));
                $this->fail('Immutable operation accepted mutation');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
        $this->assertSame($op->payload(), $this->outbox->get($op->id)->payload());
    }

    public function test_deactivation_and_reactivation_reserve_distinct_versions(): void
    {
        $op = $this->prepared();
        $this->confirmed($op);
        $this->assertTrue($this->outbox->status((string) $this->user->id)['currently_synchronized']);
        $this->user->update(['is_active' => false]);
        $revoke = $this->outbox->prepare($this->user, $this->actor);
        $this->confirmed($revoke);
        $this->user->update(['is_active' => true]);
        $active = $this->outbox->prepare($this->user, $this->actor);
        $this->assertSame(3, $active->payload()['version']);
        $this->assertTrue($active->payload()['is_active']);
    }

    public function test_unauthorized_observation_cannot_create_state(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->outbox->recordRemoteObservation($this->snapshot(), 1, false, 'UNAUTHORIZED', $this->user);
    }

    public function test_revocation_intent_survives_reactivation_while_an_active_put_is_unresolved(): void
    {
        $op = $this->prepared();
        $this->user->update(['is_active' => false]);
        $this->assertNull($this->outbox->prepare($this->user, $this->actor));
        $this->user->update(['is_active' => true]);
        $this->assertNull($this->outbox->prepare($this->user, $this->actor));
        $this->confirmed($op);
        $revocation = $this->outbox->prepareNext((string) $this->user->id, $this->actor);
        $this->assertFalse($revocation->payload()['is_active']);
        $this->confirmed($revocation);
        $active = $this->outbox->prepareNext((string) $this->user->id, $this->actor);
        $this->assertTrue($active->payload()['is_active']);
        $this->assertSame(3, $active->payload()['version']);
    }

    public function test_verified_existing_remote_version_is_not_assumed_zero(): void
    {
        $this->outbox->recordRemoteObservation($this->snapshot(), 73, false, 'REMOTE-73', $this->actor);
        $this->user->syncRoles(['consulta']);
        $this->assertSame(74, $this->outbox->prepare($this->user, $this->actor)->payload()['version']);
    }

    public function test_other_users_can_have_independent_pending_operations(): void
    {
        $first = $this->prepared();
        $other = User::factory()->create();
        $other->assignRole('operador');
        UserSsoIdentity::create(['user_id' => $other->id, 'issuer' => self::ISSUER, 'subject' => 'other-subject']);
        $snapshot = app(AccessSnapshotBuilder::class)->build($other);
        $this->observeAbsent($snapshot);
        $second = $this->outbox->prepare($other, $this->actor);
        $this->assertSame(1, $second->payload()['version']);
        $this->assertNotSame($first->payload()['operation_id'], $second->payload()['operation_id']);
        $this->assertDatabaseCount('aaud_auth_access_operations', 2);
    }

    public function test_safe_reconciliation_of_a_definitive_rejection_allows_a_new_version(): void
    {
        $op = $this->prepared();
        $claim = $this->outbox->claim($op->id);
        $this->assertSame('failed', $this->outbox->recordFailure($op->id, $claim->claimId, new AaudAuthRequestException('forbidden'))->status);
        $this->outbox->reconcile($op->id, app(AccessSnapshotBuilder::class)->revocation($op->payload()), 0, true, 'CONFIRMED-REJECTION', $this->actor);
        $this->assertSame('cancelled', $this->outbox->get($op->id)->status);
        $this->assertSame(2, $this->outbox->prepareNext((string) $this->user->id, $this->actor)->payload()['version']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'aaud_auth.access.reconciled']);
    }

    public function test_duplicate_current_receipt_is_confirmed_and_kept_normalized(): void
    {
        $op = $this->prepared();
        $claim = $this->outbox->claim($op->id);
        $p = $op->payload();
        $receipt = UserAccessResult::fromReceipt(['status' => 'duplicate', 'data' => $p + ['application_code' => 'pazysalvo', 'confirmed_at' => '2026-10-21T10:00:00-05:00'],
            'current_version' => 1, 'historical' => false, 'current_state' => ['version' => 1, 'is_active' => true, 'roles' => ['operador'], 'catalog_version' => 1]],
            $this->snapshot(), 1, $p['operation_id']);
        $confirmed = $this->outbox->confirm($op->id, $claim->claimId, $receipt);
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame('duplicate', $confirmed->receipt['status']);
        $this->assertTrue($this->outbox->status((string) $this->user->id)['currently_synchronized']);
    }

    public function test_new_migration_rollback_on_empty_tables_and_refusal_to_lose_data(): void
    {
        $migration = require database_path('migrations/2026_10_22_000001_create_aaud_auth_access_outbox.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('aaud_auth_access_operations'));
        $migration->up();
        $this->prepared();
        try {
            $migration->down();
            $this->fail('Durable records must survive rollback');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('durable', $e->getMessage());
        }
        $this->assertDatabaseCount('aaud_auth_access_operations', 1);
    }

    public function test_postgresql_rejects_live_claim_replacement_and_fabricated_confirmation(): void
    {
        $op = $this->prepared();
        $claim = $this->outbox->claim($op->id);
        foreach ([['claim_id' => (string) Str::uuid()], ['lease_expires_at' => now()->addHour()],
            ['status' => 'confirmed', 'claim_id' => null, 'lease_expires_at' => null, 'confirmed_at' => now(), 'receipt' => '{}']] as $mutation) {
            try {
                DB::transaction(fn () => DB::table('aaud_auth_access_operations')->where('id', $op->id)->update($mutation));
                $this->fail('Unsafe transition accepted');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
        $this->assertSame($claim->claimId, $this->outbox->get($op->id)->claimId);
    }

    public function test_postgresql_preserves_reserved_version_and_identity(): void
    {
        $this->prepared();
        foreach ([['last_reserved_version' => null], ['subject' => 'another-subject']] as $mutation) {
            try {
                DB::transaction(fn () => DB::table('aaud_auth_access_states')->update($mutation));
                $this->fail('Version/identity mutation accepted');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
    }

    public function test_historical_receipt_requires_observation_of_at_least_its_current_version(): void
    {
        $op = $this->prepared();
        $claim = $this->outbox->claim($op->id);
        $p = $op->payload();
        $receipt = UserAccessResult::fromReceipt(['status' => 'duplicate', 'data' => $p + ['application_code' => 'pazysalvo', 'confirmed_at' => '2026-10-21T10:00:00-05:00'],
            'current_version' => 9, 'historical' => true, 'current_state' => ['version' => 9, 'is_active' => false, 'roles' => [], 'catalog_version' => 1]],
            $this->snapshot(), 1, $p['operation_id']);
        $this->outbox->confirm($op->id, $claim->claimId, $receipt);
        $this->expectException(AccessPersistenceException::class);
        $this->outbox->reconcile($op->id, app(AccessSnapshotBuilder::class)->revocation($p), 2, false, 'STALE-OBSERVATION', $this->actor);
    }

    public function test_unrecognized_delivery_error_is_sanitized_and_remains_uncertain(): void
    {
        $op = $this->prepared();
        $claim = $this->outbox->claim($op->id);
        $failure = $this->outbox->recordFailure($op->id, $claim->claimId, new AaudAuthRequestException('private-fixture-body'), 0);
        $this->assertSame('uncertain', $failure->status);
        $this->assertDatabaseHas('aaud_auth_access_operations', ['id' => $op->id, 'last_error_code' => 'unexpected_response', 'has_uncertain_attempt' => true]);
        $this->assertStringNotContainsString('private-fixture-body', DB::table('audit_logs')->pluck('metadata')->toJson());
    }
}
