<?php

namespace App\Integrations\AaudAuth\Access;

use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Exceptions\AccessPersistenceException as Failure;
use App\Integrations\AaudAuth\Exceptions\TechnicalAuthenticationException;
use App\Integrations\AaudAuth\Http\RemoteUserAccess;
use App\Integrations\AaudAuth\Http\UserAccessResult;
use App\Models\User;
use App\Services\AdministrativeTransaction;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Persistence only. Explicit calls; no HTTP, hooks, workers or dispatch. */
final class AccessOutbox
{
    public function __construct(private readonly AccessSnapshotBuilder $builder, private readonly AuditLogger $audit) {}

    public function prepare(User $user, User $actor): ?AccessOperation
    {
        return $this->locked(function () use ($user, $actor) {
            $this->authorize($actor);

            return $this->desired((string) $user->getKey(), $this->builder->build($user), $actor->id);
        });
    }

    public function prepareRevocation(array $previousAssociation, User $actor): ?AccessOperation
    {
        return $this->locked(function () use ($previousAssociation, $actor) {
            $this->authorize($actor);
            $old = $this->builder->revocation($previousAssociation);
            $this->target($old->applicationUserId, $actor->id);
            $this->requireRevocation($old);

            return $this->desired($old->applicationUserId, $old, $actor->id);
        });
    }

    public function prepareReplacement(array $previousAssociation, User $user, User $actor): ?AccessOperation
    {
        return $this->locked(function () use ($previousAssociation, $user, $actor) {
            $this->authorize($actor);
            $old = $this->builder->revocation($previousAssociation);
            $new = $this->builder->build($user);
            if (! $new || $new->applicationUserId !== $old->applicationUserId || $this->sameIdentity($new->payload(), $old->payload())) {
                throw new Failure('replacement');
            }
            $this->target($old->applicationUserId, $actor->id);
            $this->requireRevocation($old);

            return $this->desired($old->applicationUserId, $new, $actor->id);
        });
    }

    /** Trusted transaction hook: authorization happened before the administrative mutation. No HTTP. */
    public function recordAdministrativeChange(string $owner, ?AccessSnapshot $snapshot, ?array $previous, int $actor): void
    {
        $this->locked(function () use ($owner, $snapshot, $previous, $actor) {
            $this->target($owner, $actor);
            if ($previous && (! $snapshot || $snapshot->issuer !== $previous['issuer'] || $snapshot->subject !== $previous['subject'])) {
                $this->requireRevocation($this->builder->revocation($previous));
            }
            $this->desired($owner, $snapshot, $actor);
            DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->update([
                'delivery_attempts' => 0, 'delivery_error' => null, 'next_delivery_at' => now(),
            ]);
        });
    }

    /** Background continuation of an already authorized, durable desired state. */
    public function continueDelivery(string $owner): ?AccessOperation
    {
        return $this->locked(fn () => $this->next($owner));
    }

    /** Only a validated technical GET, fenced by the target delivery claim, can initialize/reconcile automatically. */
    public function observeDelivery(string $owner, string $claim, RemoteUserAccess $remote): void
    {
        $this->locked(function () use ($owner, $claim, $remote) {
            $target = DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->lockForUpdate()->first();
            if (! $target || $target->delivery_claim !== $claim || ! now()->lt($target->delivery_lease_until)) {
                throw new Failure('claim');
            }
            $snapshot = $remote->snapshotFor($owner);
            $state = $this->state($snapshot);
            $row = $this->unresolved()->where('state_id', $state->id)->first();
            if ($row && $row->application_user_id !== $owner) {
                throw new Failure('association');
            }
            if ($row && $row->status === 'pending' && $row->attempts === 0 && ! $row->has_uncertain_attempt && $row->receipt === null) {
                $wanted = $this->decode($target->required_revocation ?? $target->desired);
                $reserved = AccessSnapshot::fromArray(array_diff_key($this->get($row->id)->payload(), array_flip(['version', 'operation_id'])))->payload();
                if ($wanted !== $reserved) {
                    // No claimant/attempt has ever existed. Superseding this intent cannot discard an uncertain PUT.
                    $this->observe($state, $snapshot, $remote->data['version'], ! $remote->data['exists']);
                    $this->update($row->id, ['status' => 'cancelled', 'last_error_code' => null, 'next_attempt_at' => null,
                        'resolution_reference' => 'superseded-before-send:'.$claim, 'resolved_by' => $target->originated_by,
                        'resolved_remote_version' => $remote->data['version'], 'resolved_at' => now()]);
                    $this->event('unsent_superseded', $target->originated_by, ['id' => $row->id, 'source' => 'technical_get', 'observed_version' => $remote->data['version']]);

                    return;
                }
            }
            if ($row) {
                // An unresolved PUT is replayed identically unless GET fences every possibly applied attempt.
                if (! in_array($row->status, ['uncertain', 'acknowledged', 'failed'], true)
                    || ($row->status === 'failed' && $row->last_error_code !== 'version_conflict')
                    || $remote->data['version'] < $row->version
                    || ($row->receipt && $remote->data['version'] < json_decode($row->receipt, true)['current_version'])) {
                    return;
                }
                $this->observe($state, $snapshot, $remote->data['version'], ! $remote->data['exists']);
                $this->update($row->id, ['status' => 'cancelled', 'last_error_code' => null, 'next_attempt_at' => null,
                    'resolution_reference' => 'technical-get:'.$claim, 'resolved_by' => $target->originated_by,
                    'resolved_remote_version' => $remote->data['version'], 'resolved_at' => now()]);
                $this->event('reconciled', $target->originated_by, ['id' => $row->id, 'source' => 'technical_get', 'observed_version' => $remote->data['version']]);
            } else {
                $this->observe($state, $snapshot, $remote->data['version'], ! $remote->data['exists']);
                $this->event('remote_observed', $target->originated_by, ['state_id' => $state->id, 'source' => 'technical_get', 'version' => $remote->data['version']]);
            }
        });
    }

    /** Verified remote state (or verified absence at version zero), never an inferred default. */
    public function recordRemoteObservation(AccessSnapshot $observed, int $version, bool $absent, string $evidence, User $actor): void
    {
        $this->locked(function () use ($observed, $version, $absent, $evidence, $actor) {
            $this->approval($actor, $evidence);
            $state = $this->state($observed);
            if ($this->unresolved()->where('state_id', $state->id)->exists()) {
                throw new Failure('unresolved');
            }
            $this->observe($state, $observed, $version, $absent);
            $this->event('remote_observed', $actor->id, ['state_id' => $state->id, 'version' => $version, 'absent' => $absent, 'evidence' => $evidence]);
        });
    }

    /** Latest desired state survives pending/uncertain operations without changing their snapshot. */
    private function desired(string $owner, ?AccessSnapshot $snapshot, int $actor): ?AccessOperation
    {
        $target = $this->target($owner, $actor);
        $published = $this->decode($target->published_identity);
        if ($published && (! $snapshot || ! $snapshot->isActive || ! $this->sameIdentity($published, $snapshot->payload()))) {
            $this->requireRevocation($this->builder->revocation($published));
        }
        DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->update([
            'desired' => $snapshot ? $this->json($snapshot->payload()) : null, 'originated_by' => $actor, 'updated_at' => now(),
        ]);
        $this->event('desired_recorded', $actor, ['application_user_id' => $owner, 'issuer' => $snapshot?->issuer, 'subject' => $snapshot?->subject]);

        return $this->next($owner);
    }

    public function prepareNext(string $owner, User $actor): ?AccessOperation
    {
        return $this->locked(function () use ($owner, $actor) {
            $this->authorize($actor);

            return $this->next($owner);
        });
    }

    private function next(string $owner): ?AccessOperation
    {
        $target = DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->lockForUpdate()->first();
        if (! $target || $this->unresolved()->where('application_user_id', $owner)->exists()) {
            return null;
        }
        $required = $this->decode($target->required_revocation);
        $desired = $required ?? $this->decode($target->desired);
        if (! $desired) {
            return null;
        }
        $snapshot = AccessSnapshot::fromArray($desired);
        $state = $this->state($snapshot);
        if ($this->unresolved()->where('state_id', $state->id)->exists() || $state->last_remote_version === null) {
            return null;
        }
        $remote = $this->decode($state->remote_snapshot);
        if ($required && ($state->remote_absent || ($remote === $snapshot->payload() && ! $snapshot->isActive))) {
            $this->clearRevocation($owner, $snapshot);

            return $this->next($owner);
        }
        if ($state->remote_absent && ! $snapshot->isActive || ! $state->remote_absent && $remote === $snapshot->payload()) {
            return null;
        }
        if ($remote && $remote['application_user_id'] !== $owner && ($remote['is_active'] || ! $snapshot->isActive)) {
            throw new Failure('association');
        }
        $version = max((int) $state->last_remote_version, (int) $state->last_reserved_version) + 1;
        if ($version > 9007199254740991) {
            throw new Failure('version');
        }
        $payload = $snapshot->payload() + ['version' => $version, 'operation_id' => (string) Str::uuid()];
        $id = DB::table('aaud_auth_access_operations')->insertGetId([
            'state_id' => $state->id, 'application_user_id' => $owner, 'version' => $version,
            'operation_id' => $payload['operation_id'], 'payload' => $this->json($payload), 'originated_by' => $target->originated_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('aaud_auth_access_states')->where('id', $state->id)->update(['last_reserved_version' => $version, 'updated_at' => now()]);
        if ($snapshot->isActive) {
            DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->update(['published_identity' => $this->json($snapshot->payload()), 'updated_at' => now()]);
        }
        $this->event('prepared', $target->originated_by, ['id' => $id, 'state_id' => $state->id, 'version' => $version, 'operation_id' => $payload['operation_id'], 'active' => $snapshot->isActive]);

        return $this->get($id);
    }

    public function get(int $id): AccessOperation
    {
        return new AccessOperation($this->row($id));
    }

    public function claim(int $id, int $leaseSeconds = 60): AccessOperation
    {
        return $this->locked(function () use ($id, $leaseSeconds) {
            $row = $this->row($id);
            if (! in_array($row->status, ['pending', 'retryable', 'uncertain'], true) || $leaseSeconds < 1 || $leaseSeconds > 3600
                || $row->next_attempt_at && now()->lt($row->next_attempt_at)) {
                throw new Failure('transition');
            }
            $this->update($id, ['status' => 'processing', 'claim_id' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds($leaseSeconds),
                'attempts' => $row->attempts + 1, 'last_attempt_at' => now(), 'next_attempt_at' => null]);
            $this->event('claimed', $row->originated_by, ['id' => $id, 'attempt' => $row->attempts + 1]);

            return $this->get($id);
        });
    }

    public function confirm(int $id, string $claimId, UserAccessResult $receipt): AccessOperation
    {
        return $this->locked(function () use ($id, $claimId, $receipt) {
            $row = $this->requireClaim($id, $claimId);
            $expected = $this->get($id)->payload();
            if ($expected !== $receipt->snapshot->payload() + ['version' => $receipt->version, 'operation_id' => $receipt->operationId]) {
                throw new Failure('receipt');
            }
            $normalized = ['status' => $receipt->status, 'data' => $receipt->snapshot->payload() + ['application_code' => 'pazysalvo',
                'version' => $receipt->version, 'operation_id' => $receipt->operationId, 'confirmed_at' => $receipt->confirmedAt],
                'current_version' => $receipt->currentVersion, 'historical' => $receipt->historical];
            if ($receipt->currentState !== null) {
                $normalized['current_state'] = $receipt->currentState;
            }
            $current = $receipt->confirmsCurrentAccess();
            $this->update($id, ['status' => $current ? 'confirmed' : 'acknowledged', 'receipt' => $this->json($normalized),
                'confirmed_at' => $current ? now() : null, 'claim_id' => null, 'lease_expires_at' => null, 'last_error_code' => null]);
            if ($current) {
                $this->observe(DB::table('aaud_auth_access_states')->find($row->state_id), $receipt->snapshot, $receipt->currentVersion, false);
                if (! $receipt->snapshot->isActive) {
                    $this->clearRevocation($row->application_user_id, $receipt->snapshot);
                }
            }
            $this->event($current ? 'confirmed' : 'acknowledged', $row->originated_by, ['id' => $id, 'version' => $receipt->version, 'historical' => $receipt->historical]);

            return $this->get($id);
        });
    }

    public function recordFailure(int $id, string $claimId, AaudAuthRequestException|TechnicalAuthenticationException $error, int $retryAfterSeconds = 60): AccessOperation
    {
        return $this->locked(function () use ($id, $claimId, $error, $retryAfterSeconds) {
            $row = $this->requireClaim($id, $claimId);
            $technical = $error instanceof TechnicalAuthenticationException;
            $allowed = ['disabled', 'configuration', 'invalid_operation', 'unauthorized', 'forbidden', 'not_found', 'version_conflict', 'idempotency_conflict', 'conflict', 'invalid_request', 'rate_limited', 'temporary', 'timeout', 'connection', 'invalid_response', 'unexpected_response', 'credentials_rejected', 'temporary_provider_error'];
            $reason = in_array($error->reason, $allowed, true) ? $error->reason : 'unexpected_response';
            $uncertain = ! $technical && ($error->isDeliveryUncertain() || $reason === 'unexpected_response');
            $retryable = in_array($reason, ['rate_limited', 'temporary_provider_error', 'timeout', 'connection', 'temporary'], true);
            if ($retryAfterSeconds < 0 || $retryAfterSeconds > 86400) {
                throw new Failure('retry_date');
            }
            $this->update($id, ['status' => $uncertain ? 'uncertain' : ($retryable ? 'retryable' : 'failed'),
                'has_uncertain_attempt' => $row->has_uncertain_attempt || $uncertain, 'last_error_code' => $reason,
                'next_attempt_at' => $uncertain || $retryable ? now()->addSeconds($retryAfterSeconds) : null, 'claim_id' => null, 'lease_expires_at' => null]);
            $this->event('delivery_recorded', $row->originated_by, ['id' => $id, 'error_code' => $reason, 'uncertain' => $uncertain]);

            return $this->get($id);
        });
    }

    public function recoverExpiredClaim(int $id): AccessOperation
    {
        return $this->locked(function () use ($id) {
            $row = $this->row($id);
            if ($row->status !== 'processing' || now()->lt($row->lease_expires_at)) {
                throw new Failure('transition');
            }
            $this->update($id, ['status' => 'uncertain', 'has_uncertain_attempt' => true, 'last_error_code' => 'lease_expired',
                'claim_id' => null, 'lease_expires_at' => null, 'next_attempt_at' => now()]);
            $this->event('claim_expired', $row->originated_by, ['id' => $id]);

            return $this->get($id);
        });
    }

    /** Cancel only with a fresh observation that fences every possibly applied attempt. No blind reset. */
    public function reconcile(int $id, AccessSnapshot $observed, int $version, bool $absent, string $evidence, User $actor): void
    {
        $this->locked(function () use ($id, $observed, $version, $absent, $evidence, $actor) {
            $this->approval($actor, $evidence);
            $row = $this->row($id);
            $payload = $this->get($id)->payload();
            if (! in_array($row->status, ['failed', 'uncertain', 'acknowledged'], true) || ! $this->sameIdentity($payload, $observed->payload())
                || $observed->applicationUserId !== $row->application_user_id
                || (($row->has_uncertain_attempt || $row->status === 'acknowledged') && $version < $row->version)
                || ($row->receipt && $version < json_decode($row->receipt, true)['current_version'])) {
                throw new Failure('reconciliation');
            }
            $this->observe(DB::table('aaud_auth_access_states')->find($row->state_id), $observed, $version, $absent);
            $this->update($id, ['status' => 'cancelled', 'last_error_code' => null, 'next_attempt_at' => null,
                'resolution_reference' => $evidence, 'resolved_by' => $actor->id, 'resolved_remote_version' => $version, 'resolved_at' => now()]);
            $this->event('reconciled', $actor->id, ['id' => $id, 'observed_version' => $version, 'evidence' => $evidence, 'absent' => $absent]);
        });
    }

    public function status(string $owner): array
    {
        $target = DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->first();
        $pending = $this->unresolved()->where('application_user_id', $owner)->get(['id', 'status', 'version', 'attempts', 'last_error_code', 'lease_expires_at']);
        $desired = $target ? $this->decode($target->desired) : null;
        $state = $desired ? DB::table('aaud_auth_access_states')->where('issuer', $desired['issuer'])->where('subject', $desired['subject'])->first() : null;

        return ['delivery_error' => $target->delivery_error ?? null, 'pending' => $pending->all(), 'has_required_revocation' => $target?->required_revocation !== null,
            'needs_reconciliation' => $desired !== null && $state?->last_remote_version === null,
            'currently_synchronized' => $target !== null && empty($target->delivery_error) && $pending->isEmpty() && $target->required_revocation === null
                && ($desired === null ? $target->published_identity === null : ($state !== null && ($state->remote_absent && ! $desired['is_active'] || ! $state->remote_absent && $this->decode($state->remote_snapshot) === $desired)))];
    }

    private function observe(object $state, AccessSnapshot $snapshot, int $version, bool $absent): void
    {
        if ($version < 0 || $version > 9007199254740991 || ($state->last_remote_version !== null && $version < $state->last_remote_version)
            || ($absent && ($version !== 0 || $snapshot->isActive)) || (! $absent && $version < 1)) {
            throw new Failure('version');
        }
        DB::table('aaud_auth_access_states')->where('id', $state->id)->update(['last_remote_version' => $version,
            'remote_absent' => $absent, 'remote_snapshot' => $this->json($snapshot->payload()), 'remote_verified_at' => now(), 'updated_at' => now()]);
    }

    private function target(string $owner, int $actor): object
    {
        if (! preg_match('/\A[1-9][0-9]{0,127}\z/D', $owner)) {
            throw new Failure('owner');
        }
        DB::table('aaud_auth_access_targets')->insertOrIgnore(['application_user_id' => $owner, 'originated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->lockForUpdate()->first();
    }

    private function state(AccessSnapshot $snapshot): object
    {
        DB::table('aaud_auth_access_states')->insertOrIgnore(['application_code' => 'pazysalvo', 'issuer' => $snapshot->issuer, 'subject' => $snapshot->subject, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('aaud_auth_access_states')->where('issuer', $snapshot->issuer)->where('subject', $snapshot->subject)->lockForUpdate()->first();
    }

    private function requireRevocation(AccessSnapshot $old): void
    {
        $target = DB::table('aaud_auth_access_targets')->where('application_user_id', $old->applicationUserId)->first();
        $required = $this->decode($target->required_revocation);
        if ($required && ! $this->sameIdentity($required, $old->payload())) {
            throw new Failure('revocation_unresolved');
        }
        DB::table('aaud_auth_access_targets')->where('application_user_id', $old->applicationUserId)->update(['required_revocation' => $this->json($old->payload()), 'updated_at' => now()]);
    }

    private function clearRevocation(string $owner, AccessSnapshot $snapshot): void
    {
        $target = DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->first();
        $changes = [];
        foreach (['published_identity', 'required_revocation'] as $field) {
            $value = $this->decode($target->$field);
            if ($value && $this->sameIdentity($value, $snapshot->payload())) {
                $changes[$field] = null;
            }
        }
        if ($changes) {
            DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->update($changes + ['updated_at' => now()]);
        }
    }

    private function row(int $id): object
    {
        $row = DB::table('aaud_auth_access_operations')->where('id', $id)->select('*')
            ->selectRaw("payload_hash = encode(sha256(convert_to(payload::text,'UTF8')),'hex') AS integrity_valid")->first();
        if (! $row || ! $row->integrity_valid) {
            throw new Failure('integrity_or_missing');
        }

        return $row;
    }

    private function requireClaim(int $id, string $claim): object
    {
        $row = $this->row($id);
        if ($row->status !== 'processing' || $row->claim_id !== $claim || ! now()->lt($row->lease_expires_at)) {
            throw new Failure('claim');
        }

        return $row;
    }

    private function update(int $id, array $values): void
    {
        DB::table('aaud_auth_access_operations')->where('id', $id)->update($values + ['updated_at' => now()]);
    }

    private function unresolved(): Builder
    {
        return DB::table('aaud_auth_access_operations')->whereNotIn('status', ['confirmed', 'cancelled']);
    }

    private function locked(Closure $work): mixed
    {
        // Match administration/catalog ordering. Coarse existing lock avoids cross-owner identity races.
        return DB::transaction(fn () => AdministrativeTransaction::run($work));
    }

    private function authorize(User $actor): void
    {
        Gate::forUser(User::findOrFail($actor->id))->authorize('manage-users');
    }

    private function approval(User $actor, string $evidence): void
    {
        $this->authorize($actor);
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,159}\z/D', $evidence)) {
            throw new Failure('evidence');
        }
    }

    private function event(string $event, int $actor, array $metadata): void
    {
        $this->audit->record('aaud_auth.access.'.$event, ['originated_by' => $actor] + $metadata, result: $event);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function decode(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return AccessSnapshot::fromArray($decoded)->payload();
    }

    private function sameIdentity(array $a, array $b): bool
    {
        return $a['issuer'] === $b['issuer'] && $a['subject'] === $b['subject'] && $a['application_user_id'] === $b['application_user_id'];
    }
}
