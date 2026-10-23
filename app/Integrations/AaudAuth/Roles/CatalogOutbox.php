<?php

namespace App\Integrations\AaudAuth\Roles;

use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Exceptions\CatalogPersistenceException;
use App\Integrations\AaudAuth\Exceptions\TechnicalAuthenticationException;
use App\Integrations\AaudAuth\Http\RoleCatalogResult;
use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persistence only. No HTTP client, queue dispatch, scheduler or administrative hooks. */
final class CatalogOutbox
{
    public function __construct(private readonly RoleCatalogBuilder $catalog, private readonly AuditLogger $audit) {}

    /** Record an externally verified observation and explicit local administrator approval. */
    public function recordRemoteObservation(int $version, string $evidenceReference, User $approvedBy): void
    {
        $this->locked(function ($state) use ($version, $evidenceReference, $approvedBy) {
            $this->requireApproval($evidenceReference, $approvedBy);
            $this->requireResolved();
            if ($version < 0 || $version > 9007199254740991
                || ($state->last_remote_version !== null && $version < (int) $state->last_remote_version)) {
                throw new CatalogPersistenceException('version');
            }
            $this->stateQuery()->update(['last_remote_version' => $version, 'remote_verified_at' => now(), 'updated_at' => now()]);
            $this->audit->record('aaud_auth.catalog.remote_observed', [
                'previous_version' => $state->last_remote_version, 'observed_version' => $version,
                'evidence_reference' => $evidenceReference, 'approved_by_user_id' => $approvedBy->getKey(),
            ], result: 'success');
        });
    }

    public function prepare(): CatalogOperation
    {
        return $this->locked(function ($state) {
            if ($state->last_remote_version === null) {
                throw new CatalogPersistenceException('unverified');
            }
            $this->requireResolved();
            $version = max((int) $state->last_remote_version, (int) ($state->last_reserved_version ?? 0)) + 1;
            if ($version > 9007199254740991) {
                throw new CatalogPersistenceException('version');
            }
            $payload = ['version' => $version, 'operation_id' => (string) Str::uuid(), 'roles' => $this->catalog->build()];
            $id = $this->operations()->insertGetId([
                'application_code' => 'pazysalvo', 'resource' => 'roles', 'version' => $version,
                'operation_id' => $payload['operation_id'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->stateQuery()->update(['last_reserved_version' => $version, 'updated_at' => now()]);
            $operation = $this->get($id);
            $this->auditOperation('prepared', $operation);

            return $operation;
        });
    }

    public function get(int $id): CatalogOperation
    {
        return $this->loadOperation($id);
    }

    private function loadOperation(int $id, bool $forUpdate = false): CatalogOperation
    {
        $query = $this->operations()->where('id', $id)->select('*')
            ->selectRaw("payload_hash = encode(sha256(convert_to(payload::text, 'UTF8')), 'hex') AS integrity_valid");
        $row = ($forUpdate ? $query->lockForUpdate() : $query)->first();
        if (! $row) {
            throw new CatalogPersistenceException('not_found');
        }
        if (! $row->integrity_valid) {
            throw new CatalogPersistenceException('integrity');
        }

        return new CatalogOperation($row);
    }

    /** Claiming does not send anything. Each attempt has a new fencing UUID and a finite lease. */
    public function claim(int $id, int $leaseSeconds = 60): CatalogOperation
    {
        return $this->locked(function ($state) use ($id, $leaseSeconds) {
            if ($state->last_remote_version === null) {
                throw new CatalogPersistenceException('unverified');
            }
            $operation = $this->loadOperation($id, true);
            if (! in_array($operation->status, ['pending', 'retryable', 'uncertain'], true)
                || $leaseSeconds < 1 || $leaseSeconds > 3600 || $operation->nextAttemptAt?->isFuture()) {
                throw new CatalogPersistenceException('transition');
            }
            $updated = $this->operations()->where('id', $id)->where('status', $operation->status)->update([
                'status' => 'processing', 'attempts' => $operation->attempts + 1, 'claim_id' => (string) Str::uuid(),
                'lease_expires_at' => now()->addSeconds($leaseSeconds), 'next_attempt_at' => null, 'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new CatalogPersistenceException('claim');
            }
            $claimed = $this->get($id);
            $this->auditOperation('claimed', $claimed);

            return $claimed;
        });
    }

    public function confirm(int $id, string $claimId, RoleCatalogResult $receipt): CatalogOperation
    {
        return $this->locked(function ($state) use ($id, $claimId, $receipt) {
            $operation = $this->requireClaim($id, $claimId);
            $expected = array_map(fn ($role) => [...$role, 'name' => trim($role['name']), 'description' => null], $operation->payload()['roles']);
            if ($receipt->version !== $operation->version || strtolower($receipt->operationId) !== $operation->operationId
                || $receipt->roles !== $expected) {
                throw new CatalogPersistenceException('receipt');
            }
            $updated = $this->operations()->where('id', $id)->where('claim_id', $claimId)->where('status', 'processing')->update([
                'status' => 'confirmed', 'confirmed_at' => now(), 'receipt_status' => $receipt->status,
                'receipt_current_version' => $receipt->currentVersion, 'claim_id' => null, 'lease_expires_at' => null,
                'last_error_code' => null, 'next_attempt_at' => null, 'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new CatalogPersistenceException('claim');
            }
            $this->stateQuery()->update([
                'last_remote_version' => max((int) $state->last_remote_version, $receipt->currentVersion),
                'remote_verified_at' => now(), 'last_confirmed_at' => now(), 'updated_at' => now(),
            ]);
            $confirmed = $this->get($id);
            $this->auditOperation('confirmed', $confirmed, ['receipt_status' => $receipt->status,
                'current_remote_version' => $receipt->currentVersion, 'historical' => $receipt->historical]);

            return $confirmed;
        });
    }

    public function recordFailure(int $id, string $claimId, AaudAuthRequestException|TechnicalAuthenticationException $failure, ?DateTimeInterface $nextAttemptAt = null): CatalogOperation
    {
        return $this->locked(function () use ($id, $claimId, $failure, $nextAttemptAt) {
            $operation = $this->requireClaim($id, $claimId);
            // Store only our fixed classification, never exception messages, OAuth bodies or headers.
            $allowed = ['disabled', 'configuration', 'invalid_operation', 'unauthorized', 'forbidden', 'version_conflict',
                'idempotency_conflict', 'conflict', 'invalid_request', 'rate_limited', 'temporary', 'timeout', 'connection',
                'invalid_response', 'unexpected_response', 'credentials_rejected', 'temporary_provider_error'];
            $code = in_array($failure->reason, $allowed, true) ? $failure->reason : 'unexpected_response';
            $technical = $failure instanceof TechnicalAuthenticationException;
            $status = match (true) {
                ! $technical && in_array($code, ['timeout', 'connection', 'temporary', 'invalid_response', 'unexpected_response'], true) => 'uncertain',
                in_array($code, ['rate_limited', 'temporary', 'connection', 'timeout', 'temporary_provider_error'], true) => 'retryable',
                default => 'failed',
            };
            if (($status !== 'failed' && ($nextAttemptAt === null || $nextAttemptAt < now()))
                || ($status === 'failed' && $nextAttemptAt !== null)) {
                throw new CatalogPersistenceException('transition');
            }
            $updated = $this->operations()->where('id', $operation->id)->where('claim_id', $claimId)->where('status', 'processing')->update([
                'status' => $status, 'last_error_code' => $code, 'next_attempt_at' => $nextAttemptAt,
                'has_uncertain_attempt' => $operation->hasUncertainAttempt || $status === 'uncertain',
                'claim_id' => null, 'lease_expires_at' => null, 'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new CatalogPersistenceException('claim');
            }
            $changed = $this->get($id);
            $this->auditOperation('delivery_recorded', $changed, ['error_code' => $code]);

            return $changed;
        });
    }

    public function recoverExpiredClaim(int $id): CatalogOperation
    {
        return $this->locked(function () use ($id) {
            $operation = $this->loadOperation($id, true);
            if ($operation->status !== 'processing' || $operation->leaseExpiresAt->isFuture()) {
                throw new CatalogPersistenceException('transition');
            }
            $this->operations()->where('id', $id)->update([
                'status' => 'uncertain', 'claim_id' => null, 'lease_expires_at' => null,
                'last_error_code' => 'lease_expired', 'has_uncertain_attempt' => true, 'next_attempt_at' => now(), 'updated_at' => now(),
            ]);
            $recovered = $this->get($id);
            $this->auditOperation('claim_expired', $recovered);

            return $recovered;
        });
    }

    /** Current means at this local transaction, based on the latest trustworthy remote observation. */
    public function isCurrentlySynchronized(): bool
    {
        return $this->inspectLocalChanges()['currently_synchronized'];
    }

    /** Metadata only. Safe for diagnostics; does not create state or expose payloads. */
    public function status(): array
    {
        return ['state' => $this->stateQuery()->first(), 'unresolved' => $this->unresolved()
            ->get(['id', 'version', 'operation_id', 'status', 'attempts', 'has_uncertain_attempt', 'next_attempt_at', 'lease_expires_at', 'last_error_code', 'updated_at'])->all()];
    }

    /** Never discard ambiguity: a verified remote version >= this operation fences all late deliveries. */
    public function cancelAfterReview(int $id, int $observedRemoteVersion, string $evidenceReference, User $approvedBy): CatalogOperation
    {
        return $this->locked(function ($state) use ($id, $observedRemoteVersion, $evidenceReference, $approvedBy) {
            $this->requireApproval($evidenceReference, $approvedBy);
            $operation = $this->loadOperation($id, true);
            if (! in_array($operation->status, ['failed', 'uncertain'], true)) {
                throw new CatalogPersistenceException('transition');
            }
            if ($observedRemoteVersion < (int) $state->last_remote_version || $observedRemoteVersion < 0
                || $observedRemoteVersion > 9007199254740991) {
                throw new CatalogPersistenceException('version');
            }
            $definitive = in_array($operation->lastErrorCode, ['disabled', 'configuration', 'invalid_operation', 'unauthorized',
                'forbidden', 'version_conflict', 'idempotency_conflict', 'conflict', 'invalid_request', 'credentials_rejected'], true);
            if (($operation->hasUncertainAttempt || ! $definitive) && $observedRemoteVersion < $operation->version) {
                throw new CatalogPersistenceException('reconciliation');
            }
            $this->operations()->where('id', $id)->where('status', $operation->status)->update([
                'status' => 'cancelled', 'resolved_at' => now(), 'resolved_remote_version' => $observedRemoteVersion,
                'next_attempt_at' => null,
                'resolution_reference' => $evidenceReference, 'resolution_approved_by' => $approvedBy->getKey(), 'updated_at' => now(),
            ]);
            $this->stateQuery()->update(['last_remote_version' => $observedRemoteVersion, 'remote_verified_at' => now(), 'updated_at' => now()]);
            $cancelled = $this->get($id);
            $this->auditOperation('cancelled', $cancelled, ['observed_remote_version' => $observedRemoteVersion,
                'evidence_reference' => $evidenceReference, 'approved_by_user_id' => $approvedBy->getKey()]);

            return $cancelled;
        });
    }

    /** Recomputed from durable snapshots, so changes during a pending operation survive any process restart. */
    public function inspectLocalChanges(): array
    {
        return $this->locked(function ($state) {
            $local = $this->catalog->build();
            $unresolved = $this->unresolved()->first();
            $latest = $unresolved ?? $this->operations()->where('status', 'confirmed')->orderByDesc('version')->first();
            $matches = $latest !== null && $this->get($latest->id)->payload()['roles'] === $local;
            $current = $unresolved === null && $matches && (int) $latest->version === (int) $state->last_remote_version;

            return ['snapshot_matches_local' => $matches, 'currently_synchronized' => $current,
                'needs_preparation' => $state->last_remote_version !== null && $unresolved === null && ! $current,
                'blocked_by_unresolved' => $unresolved !== null];
        });
    }

    private function locked(Closure $work): mixed
    {
        // Global lock order: existing administration advisory lock -> catalog state -> operation.
        // A nested savepoint is intentional: audit failure must also roll back this unit inside a caller's transaction.
        return DB::transaction(function () use ($work) {
            DB::select('SELECT pg_advisory_xact_lock(17012026, 1)');
            $state = $this->stateQuery()->lockForUpdate()->first();
            if (! $state) {
                throw new CatalogPersistenceException('not_found');
            }

            return $work($state);
        });
    }

    private function requireResolved(): void
    {
        if ($this->unresolved()->exists()) {
            throw new CatalogPersistenceException('unresolved');
        }
    }

    private function requireClaim(int $id, string $claimId): CatalogOperation
    {
        $operation = $this->loadOperation($id, true);
        if ($operation->status !== 'processing' || $operation->claimId !== $claimId || ! $operation->leaseExpiresAt?->isFuture()) {
            throw new CatalogPersistenceException('claim');
        }

        return $operation;
    }

    private function stateQuery(): Builder
    {
        return DB::table('aaud_auth_catalog_states')->where('application_code', 'pazysalvo')->where('resource', 'roles');
    }

    private function operations(): Builder
    {
        return DB::table('aaud_auth_catalog_operations')->where('application_code', 'pazysalvo')->where('resource', 'roles');
    }

    private function unresolved(): Builder
    {
        return $this->operations()->whereNotIn('status', ['confirmed', 'cancelled']);
    }

    private function requireApproval(string $evidenceReference, User $approvedBy): void
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,159}\z/D', $evidenceReference)
            || ! User::query()->whereKey($approvedBy->getKey())->where('is_active', true)->role('admin')->exists()) {
            throw new CatalogPersistenceException('approval');
        }
    }

    private function auditOperation(string $event, CatalogOperation $operation, array $extra = []): void
    {
        $this->audit->record('aaud_auth.catalog.'.$event, ['operation_id' => $operation->operationId,
            'version' => $operation->version, 'status' => $operation->status, 'attempts' => $operation->attempts,
            'payload_hash' => $operation->payloadHash, ...$extra], result: $operation->status);
    }
}
