<?php

namespace App\Models\Concerns;

use App\Models\Role;
use App\Services\AdministrativeTransaction;
use Illuminate\Validation\ValidationException;

trait HasSingleRole
{
    public function assignRole(...$roles): static
    {
        return $this->syncRoles(...$roles);
    }

    public function syncRoles(...$roles): static
    {
        if (! $this->exists) {
            throw ValidationException::withMessages(['role' => 'Guarde el usuario antes de asignar su rol.']);
        }

        return AdministrativeTransaction::run(function () use ($roles) {
            $ids = $this->collectRoles($roles);
            if (count($ids) > 1) {
                throw ValidationException::withMessages(['role' => 'Paz y Salvo permite un solo rol por usuario.']);
            }
            $role = $ids ? Role::whereKey($ids[0])->lockForUpdate()->firstOrFail() : null;
            if ($role && ! filter_var($role->is_active, FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages(['role' => 'No se puede asignar un rol inactivo.']);
            }
            $current = static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            AdministrativeTransaction::protectLastAdmin($current, $role?->name === 'admin');
            $old = $current->roles()->pluck('roles.id')->all();
            if ($old !== $ids) {
                AdministrativeTransaction::accessChanged($current);
                $this->roles()->sync($ids);
                if ($old !== []) {
                    $current->increment('session_version');
                    $this->session_version = $current->session_version;
                    $this->syncOriginalAttribute('session_version');
                }
            }
            $this->unsetRelation('roles');
            $this->forgetWildcardPermissionIndex();

            return $this;
        });
    }

    public function removeRole(...$roles): static
    {
        return AdministrativeTransaction::run(function () use ($roles) {
            $ids = $this->collectRoles($roles);
            $remaining = $this->roles()->whereNotIn('roles.id', $ids)->get();

            return $this->syncRoles($remaining);
        });
    }

    public function hasOperationalRole(): bool
    {
        return $this->roles()->count() === 1 && $this->roles()->where('is_active', true)->exists();
    }
}
