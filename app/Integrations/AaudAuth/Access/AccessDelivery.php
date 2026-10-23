<?php

namespace App\Integrations\AaudAuth\Access;

use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Exceptions\TechnicalAuthenticationException;
use App\Integrations\AaudAuth\Http\AaudAuthClient;
use App\Integrations\AaudAuth\Roles\CatalogOutbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** After-commit delivery and scheduled recovery. No HTTP inside a database transaction. */
final class AccessDelivery
{
    private const MAX_ATTEMPTS = 8;

    public function __construct(private readonly AccessOutbox $outbox, private readonly AaudAuthClient $client, private readonly CatalogOutbox $catalog) {}

    public function deliver(string $owner): void
    {
        if (! config('aaud-auth.automatic') || ! config('aaud-auth.enabled') || DB::transactionLevel() !== 0) {
            return;
        }
        $claim = (string) Str::uuid();
        $seconds = min(3600, max(180, (int) config('aaud-auth.timeout', 10) * 16 + 30));
        $claimed = DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)
            ->where('delivery_attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($q) => $q->whereNull('next_delivery_at')->orWhere('next_delivery_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('delivery_claim')->orWhere('delivery_lease_until', '<=', now()))
            ->update(['delivery_claim' => $claim, 'delivery_lease_until' => now()->addSeconds($seconds),
                'delivery_attempts' => DB::raw('delivery_attempts + 1'), 'delivery_error' => null]);
        if (! $claimed) {
            return;
        }
        $error = null;
        $retryAfter = 0;
        try {
            for ($step = 0; $step < 6; $step++) {
                $target = DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->first();
                if ($target->delivery_claim !== $claim || ! now()->lt($target->delivery_lease_until)) {
                    return;
                }
                $row = DB::table('aaud_auth_access_operations')->where('application_user_id', $owner)->whereNotIn('status', ['confirmed', 'cancelled'])->first();
                if ($row && $row->status === 'processing') {
                    if (now()->lt($row->lease_expires_at)) {
                        $error = 'processing';
                        break;
                    }
                    $this->outbox->recoverExpiredClaim($row->id);
                    $row = DB::table('aaud_auth_access_operations')->find($row->id);
                }
                if ($row && $row->next_attempt_at && now()->lt($row->next_attempt_at)) {
                    $error = 'backoff';
                    break;
                }
                if ($row && $row->status === 'failed' && $row->last_error_code !== 'version_conflict') {
                    $error = 'needs_review';
                    break;
                }
                $data = $row ? $this->outbox->get($row->id)->payload() : json_decode($target->required_revocation ?? $target->desired ?? 'null', true);
                if (! $data) {
                    break;
                }
                $snapshot = AccessSnapshot::fromArray(array_diff_key($data, array_flip(['version', 'operation_id'])));
                $remote = $this->client->getUserAccess($snapshot);
                $this->outbox->observeDelivery($owner, $claim, $remote);
                $operation = $this->outbox->continueDelivery($owner);
                if (! $operation) {
                    $row = DB::table('aaud_auth_access_operations')->where('application_user_id', $owner)->whereNotIn('status', ['confirmed', 'cancelled'])->first();
                    if ($row) {
                        if (! in_array($row->status, ['pending', 'retryable', 'uncertain'], true)) {
                            $error = 'needs_review';
                            break;
                        }
                        $operation = $this->outbox->get($row->id);
                    } else {
                        // A GET may clear an old revocation barrier, exposing a different desired identity.
                        $fresh = DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->first();
                        $next = json_decode($fresh->required_revocation ?? $fresh->desired ?? 'null', true);
                        if ($next && ($next['issuer'] !== $snapshot->issuer || $next['subject'] !== $snapshot->subject)) {
                            continue;
                        }
                        break;
                    }
                }
                $payload = $operation->payload();
                if ($payload['is_active'] && ! $this->catalogReady($payload['roles'])) {
                    $error = $this->catalogNeedsReview() ? 'needs_review' : 'catalog_pending';
                    break;
                }
                $operation = $this->outbox->claim($operation->id, min(3600, max(60, (int) config('aaud-auth.timeout', 10) * 2 + 15)));
                try {
                    $receipt = $this->client->syncUserAccess($operation->payload());
                    $this->outbox->confirm($operation->id, $operation->claimId, $receipt);
                    if (! $receipt->confirmsCurrentAccess()) {
                        $error = 'historical';
                        break;
                    }
                } catch (AaudAuthRequestException|TechnicalAuthenticationException $failure) {
                    $this->outbox->recordFailure($operation->id, $operation->claimId, $failure, $this->delay($operation->attempts, $failure));
                    throw $failure;
                }
            }
        } catch (AaudAuthRequestException|TechnicalAuthenticationException $failure) {
            $error = $failure->reason;
            $retryAfter = $failure instanceof AaudAuthRequestException ? (int) $failure->retryAfterSeconds : 0;
        } catch (Throwable $failure) {
            // Never log exception objects: HTTP exceptions can retain Authorization/request bodies.
            $error = 'internal';
            Log::error('AAUD AUTH access delivery interrupted.', ['exception_class' => $failure::class, 'application_user_id' => $owner]);
        } finally {
            // Serialize final scheduling with administrative desired-state writes; never overwrite a newer due time.
            DB::transaction(function () use ($owner, $claim, $error, $retryAfter) {
                $target = DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->lockForUpdate()->first();
                if (! $target || $target->delivery_claim !== $claim) {
                    return;
                }
                if ($error === null && ! $this->outbox->status($owner)['currently_synchronized']) {
                    $error = 'pending';
                }
                $permanent = in_array($error, ['needs_review', 'configuration', 'forbidden', 'unauthorized', 'credentials_rejected', 'not_found', 'invalid_request', 'idempotency_conflict', 'conflict'], true);
                $waiting = in_array($error, ['catalog_pending', 'processing', 'backoff', 'pending'], true);
                $attempts = max(0, $target->delivery_attempts - ($waiting ? 1 : 0));
                $exhausted = $attempts >= self::MAX_ATTEMPTS;
                $next = now()->addSeconds($error ? max(min(86400, $retryAfter), $this->delay($attempts)) : 3600);
                if ($waiting) {
                    $operation = DB::table('aaud_auth_access_operations')->where('application_user_id', $owner)->whereNotIn('status', ['confirmed', 'cancelled'])->first();
                    $until = $error === 'processing' ? $operation?->lease_expires_at : $operation?->next_attempt_at;
                    if ($until && $next->lt($until)) {
                        $next = Carbon::parse($until);
                    }
                }
                DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->where('delivery_claim', $claim)
                    ->update(['delivery_claim' => null, 'delivery_lease_until' => null,
                        'delivery_error' => $exhausted || $permanent ? 'needs_review' : $error,
                        'next_delivery_at' => $exhausted || $permanent ? null : $next,
                        'delivery_attempts' => $error === null ? 0 : $attempts]);
            });
        }
    }

    public function recover(): void
    {
        if (! config('aaud-auth.automatic') || ! config('aaud-auth.enabled')) {
            return;
        }
        $this->deliverCatalog();
        // Only explicitly enrolled targets. Historical SSO links are never discovered/published here.
        DB::table('aaud_auth_access_targets')->where('delivery_attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($q) => $q->whereNull('delivery_error')->orWhere('delivery_error', '<>', 'needs_review'))
            ->where(fn ($q) => $q->whereNull('next_delivery_at')->orWhere('next_delivery_at', '<=', now()))
            ->orderByRaw('next_delivery_at ASC NULLS FIRST')->orderBy('application_user_id')->limit(100)->pluck('application_user_id')->each(fn ($owner) => $this->deliver($owner));
    }

    public function deliverCatalog(): void
    {
        if (! config('aaud-auth.automatic') || ! config('aaud-auth.enabled') || DB::transactionLevel() !== 0) {
            return;
        }
        try {
            if ($this->catalogNeedsReview()) {
                return;
            }
            $changes = $this->catalog->inspectLocalChanges();
            if ($changes['needs_preparation']) {
                $this->catalog->prepare();
            }
            $row = $this->catalog->status()['unresolved'][0] ?? null;
            if (! $row) {
                return;
            }
            $op = $this->catalog->get($row->id);
            if ($op->status === 'processing' && now()->gte($op->leaseExpiresAt)) {
                $op = $this->catalog->recoverExpiredClaim($op->id);
            }
            if (! in_array($op->status, ['pending', 'retryable', 'uncertain'], true) || $op->attempts >= self::MAX_ATTEMPTS
                || ($op->nextAttemptAt && now()->lt($op->nextAttemptAt))) {
                return;
            }
            $op = $this->catalog->claim($op->id, min(3600, max(60, (int) config('aaud-auth.timeout', 10) * 2 + 15)));
            try {
                $payload = $op->payload();
                $this->catalog->confirm($op->id, $op->claimId, $this->client->syncRoleCatalog($payload['version'], $payload['operation_id'], $payload['roles']));
            } catch (AaudAuthRequestException|TechnicalAuthenticationException $failure) {
                $retry = ($failure instanceof AaudAuthRequestException && $failure->isDeliveryUncertain())
                    || in_array($failure->reason, ['rate_limited', 'temporary', 'connection', 'timeout', 'temporary_provider_error'], true);
                $this->catalog->recordFailure($op->id, $op->claimId, $failure, $retry ? now()->addSeconds($this->delay($op->attempts, $failure)) : null);
            }
        } catch (Throwable $failure) {
            Log::warning('AAUD AUTH catalog requires recovery.', ['exception_class' => $failure::class]);
        }
    }

    /** Catalog has no verified GET: never invent recovery from an unknown/historical catalog version. */
    public function catalogNeedsReview(): bool
    {
        $status = $this->catalog->status();
        if ($status['state']?->last_remote_version === null) {
            return true;
        }
        foreach ($status['unresolved'] as $operation) {
            if ($operation->status === 'failed' || $operation->attempts >= self::MAX_ATTEMPTS) {
                return true;
            }
        }
        $latest = DB::table('aaud_auth_catalog_operations')->where('status', 'confirmed')->orderByDesc('version')->first();

        return $latest !== null && $latest->version < $status['state']->last_remote_version;
    }

    private function catalogReady(array $codes): bool
    {
        $this->deliverCatalog();
        if (! $this->catalog->isCurrentlySynchronized()) {
            return false;
        }
        $latest = DB::table('aaud_auth_catalog_operations')->where('status', 'confirmed')->orderByDesc('version')->first();
        $roles = $latest ? $this->catalog->get($latest->id)->payload()['roles'] : [];
        $active = array_column(array_filter($roles, fn ($role) => $role['active']), 'code');

        return array_diff($codes, $active) === [];
    }

    private function delay(int $attempt, AaudAuthRequestException|TechnicalAuthenticationException|null $failure = null): int
    {
        $after = $failure instanceof AaudAuthRequestException ? $failure->retryAfterSeconds : 0;

        return min(86400, max((int) $after, min(3600, 30 * 2 ** min($attempt, 7))));
    }

    public function message(array $owners): string
    {
        $current = true;
        foreach ($owners as $owner) {
            $status = $this->outbox->status((string) $owner);
            if ($status['delivery_error'] === 'needs_review' || collect($status['pending'])->contains(fn ($op) => $op->status === 'failed')) {
                return 'Cambio local guardado. Error de sincronización que requiere atención.';
            }
            $current = $current && $status['currently_synchronized'];
        }

        return $current ? 'Sincronizado correctamente con AAUD AUTH.' : 'Cambio local guardado, sincronización con AAUD AUTH pendiente.';
    }
}
