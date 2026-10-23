<?php

namespace App\Models;

use App\Integrations\AaudAuth\Access\AccessDelivery;
use App\Integrations\AaudAuth\Roles\CatalogOutbox;
use App\Services\AdministrativeTransaction;
use App\Support\RoleCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Role extends \Spatie\Permission\Models\Role
{
    public const RESERVED_NAMES = ['admin', 'supervisor', 'operador', 'consulta', 'administrador_general'];

    public function save(array $options = [])
    {
        return AdministrativeTransaction::run(function () use ($options) {
            if ($this->exists && $this->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'El código del rol es inmutable.']);
            }
            if (! $this->exists) {
                $this->code ??= RoleCode::fromName($this->name);
                RoleCode::validate($this->code);
                if (static::where('guard_name', $this->guard_name)->where('code', $this->code)->exists()) {
                    throw ValidationException::withMessages(['code' => 'El código normalizado ya existe en este guard. Defina un código distinto al crear el rol.']);
                }
            }
            if ($this->exists) {
                $current = static::whereKey($this->id)->lockForUpdate()->firstOrFail();
                if ($this->isDirty('name') && in_array($current->name, self::RESERVED_NAMES, true)) {
                    throw ValidationException::withMessages(['name' => 'El nombre de este rol del sistema no puede cambiarse.']);
                }
                if ($this->isDirty('is_active') && ! $this->is_active) {
                    if ($current->name === 'admin' || DB::table('model_has_roles')->where('role_id', $this->id)->exists()) {
                        throw ValidationException::withMessages(['role' => 'No se puede desactivar admin ni un rol asignado.']);
                    }
                }
            }

            $catalogChanged = ! $this->exists || $this->isDirty(['name', 'code', 'is_active', 'guard_name']);
            $saved = parent::save($options);
            if ($saved && $catalogChanged && $this->guard_name === 'web' && config('aaud-auth.automatic')) {
                $catalog = app(CatalogOutbox::class);
                if (! app(AccessDelivery::class)->catalogNeedsReview() && $catalog->inspectLocalChanges()['needs_preparation']) {
                    $catalog->prepare();
                }
                DB::afterCommit(fn () => app(AccessDelivery::class)->deliverCatalog());
            }

            return $saved;
        });
    }

    public function delete()
    {
        throw ValidationException::withMessages(['role' => 'Los roles se desactivan; no se eliminan físicamente.']);
    }
}
