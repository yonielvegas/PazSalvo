<?php

namespace App\Integrations\AaudOauth\Roles;

use App\Integrations\AaudOauth\Access\AccessDelivery;
use App\Integrations\AaudOauth\Access\AccessOutbox;
use App\Integrations\AaudOauth\Access\AccessSnapshot;
use App\Models\User;
use App\Services\AdministrativeTransaction;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class CatalogRecovery
{
    public function __construct(private readonly LocalCatalogReader $remote, private readonly RoleCatalogBuilder $builder,
        private readonly CatalogOutbox $catalog, private readonly AccessOutbox $access, private readonly AccessDelivery $delivery) {}

    /** Pure preview: remote READ ONLY plus local SELECT; no token, HTTP, observation or preparation. */
    public function plan(int $expectedVersion): array
    {
        $remote = $this->remote->read();
        if ($remote['version'] !== $expectedVersion) {
            throw new RuntimeException('La versión remota cambió. Detenga y revise la recuperación.');
        }
        $local = $this->builder->build();
        $observed = array_values(array_map(fn ($role) => ['code' => $role['code'], 'name' => $role['name'], 'active' => $role['active']],
            array_filter($remote['roles'], fn ($role) => ! $role['deleted_at_source'])));
        usort($observed, fn ($a, $b) => strcmp($a['code'], $b['code']));
        $missing = array_values(array_filter($remote['roles'], fn ($role) => ! $role['deleted_at_source'] && ! in_array($role['code'], array_column($local, 'code'), true)));
        $targets = DB::table('aaud_auth_access_targets')->where('delivery_error', 'needs_review')->orderBy('application_user_id')->pluck('application_user_id')->all();
        $operations = DB::table('aaud_auth_access_operations')->whereIn('application_user_id', $targets)->whereNotIn('status', ['confirmed', 'cancelled'])
            ->orderBy('id')->get(['id', 'application_user_id', 'version', 'operation_id', 'payload_hash', 'status', 'attempts'])->map(fn ($row) => (array) $row)->all();
        $plan = ['application_code' => 'pazysalvo', 'remote_version' => $remote['version'], 'candidate_version' => $observed === $local ? null : $remote['version'] + 1,
            'roles' => $local, 'omitted_remote_roles' => $missing, 'remote_roles' => $observed, 'blocked_targets' => $targets, 'blocked_operations' => $operations];
        $plan['approval_hash'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $plan + ['observation' => $remote];
    }

    /** Explicit approval of this exact plan. HTTP happens only after durable local commits. */
    public function execute(int $expectedVersion, string $approvedHash, User $actor): array
    {
        $this->authorize($actor);
        if (! config('aaud-oauth.enabled') || ! config('aaud-oauth.automatic')) {
            throw new RuntimeException('La integración automática debe estar habilitada.');
        }
        $plan = $this->plan($expectedVersion); // Fresh remote SELECT immediately before recording anything.
        if (! hash_equals($plan['approval_hash'], $approvedHash)) {
            throw new RuntimeException('El catálogo o sus efectos cambiaron desde la aprobación. Genere otra previsualización.');
        }
        $evidence = 'catalog-recovery:'.$approvedHash;
        AdministrativeTransaction::run(function () use ($plan, $actor, $evidence) {
            // Prevent local role edits between preview comparison and snapshot reservation.
            if ($this->builder->build() !== $plan['roles']) {
                throw new RuntimeException('El catálogo local cambió. No se preparó ninguna operación.');
            }
            $status = $this->catalog->status();
            if ($status['unresolved'] !== [] || $status['state']->last_reserved_version !== null) {
                throw new RuntimeException('Esta recuperación inicial requiere un historial local vacío. Revise las operaciones existentes.');
            }
            $this->catalog->reconcileObservedCatalog($plan['observation'], $evidence, $actor);
            if ($plan['candidate_version'] !== null) {
                $this->catalog->prepare();
            }
        });
        $this->delivery->deliverCatalog();
        if (! $this->catalog->isCurrentlySynchronized()) {
            throw new RuntimeException('Catálogo pendiente o incierto: conserve la operación; no se reanudaron accesos. El scheduler conserva su entrega.');
        }
        // Verify the remote persisted catalog again; no fake receipt and no historical acknowledgement shortcut.
        $after = $this->plan($plan['candidate_version'] ?? $expectedVersion);
        if ($after['candidate_version'] !== null) {
            throw new RuntimeException('El catálogo persistido remoto no coincide. No se reanudaron accesos.');
        }
        $resumed = [];
        foreach ($plan['blocked_targets'] as $owner) {
            $this->resumeTarget((string) $owner, $actor, $evidence);
            $this->delivery->deliver((string) $owner);
            $resumed[(string) $owner] = $this->access->status((string) $owner)['currently_synchronized'];
        }

        return ['catalog_version' => $after['remote_version'], 'targets' => $resumed];
    }

    /** Also usable after a transient catalog delivery was later confirmed by the existing scheduler. */
    public function resumeTarget(string $owner, User $actor, string $evidence): void
    {
        $this->authorize($actor);
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,159}\z/D', $evidence)) {
            throw new RuntimeException('Referencia de recuperación inválida.');
        }
        AdministrativeTransaction::run(function () use ($owner, $actor, $evidence) {
            if (! $this->catalog->isCurrentlySynchronized()) {
                throw new RuntimeException('El catálogo aún no está verificado y vigente.');
            }
            $target = DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->lockForUpdate()->first();
            $row = DB::table('aaud_auth_access_operations')->where('application_user_id', $owner)->whereNotIn('status', ['confirmed', 'cancelled'])->lockForUpdate()->first();
            if (! $target || $target->delivery_error !== 'needs_review' || $target->delivery_claim !== null || $target->required_revocation !== null
                || ! $row || $row->status !== 'pending' || $row->attempts !== 0 || $row->claim_id !== null || $row->has_uncertain_attempt
                || $row->receipt !== null || $row->last_error_code !== null) {
                throw new RuntimeException('El acceso no es una operación pendiente sin intentos detenida antes del envío. Requiere revisión específica.');
            }
            $operation = $this->access->get($row->id); // Includes PostgreSQL payload hash validation.
            $payload = $operation->payload();
            $snapshot = AccessSnapshot::fromArray(array_diff_key($payload, array_flip(['version', 'operation_id'])));
            if (! $target->desired || AccessSnapshot::fromArray(json_decode($target->desired, true, flags: JSON_THROW_ON_ERROR))->payload() !== $snapshot->payload()
                || ($snapshot->isActive && array_diff($snapshot->roles, array_column(array_filter($this->catalog->verifiedRoles(), fn ($role) => $role['active']), 'code')) !== [])) {
                throw new RuntimeException('El acceso preparado o su rol ya no coincide con el estado autorizado.');
            }
            DB::table('aaud_auth_access_targets')->where('application_user_id', $owner)->update(['delivery_error' => null,
                'delivery_attempts' => 0, 'next_delivery_at' => now()]);
            app(AuditLogger::class)->record('aaud_oauth.access.catalog_recovery_resumed', ['approved_by_user_id' => $actor->id,
                'application_user_id' => $owner, 'operation_id' => $payload['operation_id'], 'version' => $payload['version'], 'payload_hash' => $operation->payloadHash,
                'evidence' => $evidence], result: 'resumed');
        });
    }

    private function authorize(User $actor): void
    {
        if (! User::whereKey($actor->id)->where('is_active', true)->role('admin')->exists()) {
            throw new RuntimeException('Se requiere un administrador local activo identificado explícitamente.');
        }
        Gate::forUser($actor)->authorize('manage-users');
    }
}
