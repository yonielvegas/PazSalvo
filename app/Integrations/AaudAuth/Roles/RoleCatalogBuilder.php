<?php

namespace App\Integrations\AaudAuth\Roles;

use App\Integrations\AaudAuth\Exceptions\InvalidRoleCatalogException;
use App\Models\Role;

final class RoleCatalogBuilder
{
    /** @return list<array{code: string, name: string, active: bool}> */
    public function build(): array
    {
        // A single SELECT provides one PostgreSQL statement snapshot. No locks or writes.
        return $this->fromRoles(Role::query()->where('guard_name', 'web')
            ->withoutEagerLoads()->get(['code', 'name', 'is_active', 'guard_name']));
    }

    /**
     * Pure transformation: reject inconsistent data instead of repairing it.
     *
     * @param  iterable<Role>  $roles
     * @return list<array{code: string, name: string, active: bool}>
     */
    public function fromRoles(iterable $roles): array
    {
        $catalog = [];
        $seen = [];
        foreach ($roles as $role) {
            if (! $role instanceof Role || ($role->getAttributes()['guard_name'] ?? null) !== 'web') {
                throw new InvalidRoleCatalogException('invalid_record');
            }
            // Raw database values prevent an attribute cast from hiding a malformed state.
            $attributes = $role->getAttributes();
            $code = $attributes['code'] ?? null;
            $name = $attributes['name'] ?? null;
            $state = $attributes['is_active'] ?? null;
            if (! is_string($code) || ! preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,79}\z/D', $code)) {
                throw new InvalidRoleCatalogException('invalid_code');
            }
            if (isset($seen[$code])) {
                throw new InvalidRoleCatalogException('duplicate_code');
            }
            if (! is_string($name) || ! mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 255
                || preg_match('/[^\p{Z}\s]/u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
                throw new InvalidRoleCatalogException('invalid_name');
            }
            if (! in_array($state, [true, false, 1, 0, '1', '0'], true)) {
                throw new InvalidRoleCatalogException('invalid_state');
            }
            $seen[$code] = true;
            $catalog[] = ['code' => $code, 'name' => $name, 'active' => in_array($state, [true, 1, '1'], true)];
            if (count($catalog) > 1000) {
                throw new InvalidRoleCatalogException('too_large');
            }
        }
        if ($catalog === []) {
            throw new InvalidRoleCatalogException('empty');
        }
        // ASCII byte ordering is independent of database/server locale.
        usort($catalog, fn (array $a, array $b) => strcmp($a['code'], $b['code']));

        return $catalog;
    }
}
