<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class MasterDataSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Idempotent authorization catalog. This seeder creates no users or agencies.
     */
    public function run(): void
    {
        $permissions = [
            'consultar paz y salvo',
            'generar paz y salvo',
            'ver historial',
            'ver detalle paz y salvo',
            'anular paz y salvo',
            'administrar usuarios',
            'administrar agencias',
            'administrar roles',
            'clients-excel.view',
            'clients-excel.download',
            'clients-excel.delete',
        ];

        $models = collect($permissions)->mapWithKeys(
            fn (string $name) => [$name => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])]
        );

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])
            ->syncPermissions($models->values());
        Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web'])
            ->syncPermissions($models->only([
                'consultar paz y salvo', 'generar paz y salvo', 'ver historial',
                'ver detalle paz y salvo', 'anular paz y salvo',
            ])->values());
        Role::firstOrCreate(['name' => 'operador', 'guard_name' => 'web'])
            ->syncPermissions($models->only([
                'consultar paz y salvo', 'generar paz y salvo', 'ver historial', 'ver detalle paz y salvo',
            ])->values());
        Role::firstOrCreate(['name' => 'consulta', 'guard_name' => 'web'])
            ->syncPermissions($models->only([
                'consultar paz y salvo', 'ver historial', 'ver detalle paz y salvo',
            ])->values());
        Role::firstOrCreate(['name' => 'administrador_general', 'guard_name' => 'web'])
            ->syncPermissions($models->only([
                'consultar paz y salvo', 'generar paz y salvo', 'ver historial', 'ver detalle paz y salvo',
            ])->values());
    }
}
