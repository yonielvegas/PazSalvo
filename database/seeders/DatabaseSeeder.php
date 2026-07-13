<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $permissions = [
            'consultar paz y salvo', 'generar paz y salvo', 'ver historial', 'ver detalle paz y salvo',
            'anular paz y salvo', 'administrar usuarios', 'administrar agencias', 'administrar roles',
        ];
        $models = collect($permissions)->mapWithKeys(fn ($name) => [$name => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])]);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->syncPermissions($models->values());
        Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web'])->syncPermissions($models->only(['consultar paz y salvo', 'generar paz y salvo', 'ver historial', 'ver detalle paz y salvo', 'anular paz y salvo'])->values());
        Role::firstOrCreate(['name' => 'operador', 'guard_name' => 'web'])->syncPermissions($models->only(['consultar paz y salvo', 'generar paz y salvo', 'ver historial', 'ver detalle paz y salvo'])->values());
        Role::firstOrCreate(['name' => 'consulta', 'guard_name' => 'web'])->syncPermissions($models->only(['consultar paz y salvo', 'ver historial', 'ver detalle paz y salvo'])->values());
        Role::firstOrCreate(['name' => 'administrador_general', 'guard_name' => 'web'])->syncPermissions($models->only(['consultar paz y salvo', 'generar paz y salvo', 'ver historial', 'ver detalle paz y salvo'])->values());

        $allowedAgencyNames = ['PH Multiplaza', 'Los Andes', 'La Gran Estacion', 'Villa Lucre', 'San Miguelito'];

        $testAgencies = [
            ['name' => 'PH Multiplaza', 'code' => 'PH-MULTIPLAZA', 'users' => ['multiplaza1@aaud.gob.pa', 'multiplaza2@aaud.gob.pa']],
            ['name' => 'Los Andes', 'code' => 'LOS-ANDES', 'users' => ['losandes1@aaud.gob.pa', 'losandes2@aaud.gob.pa']],
            ['name' => 'La Gran Estacion', 'code' => 'GRAN-ESTACION', 'users' => ['granestacion1@aaud.gob.pa', 'granestacion2@aaud.gob.pa']],
            ['name' => 'Villa Lucre', 'code' => 'VILLA-LUCRE', 'users' => ['villalucre1@aaud.gob.pa', 'villalucre2@aaud.gob.pa']],
            ['name' => 'San Miguelito', 'code' => 'SAN-MIGUELITO', 'users' => ['sanmiguelito1@aaud.gob.pa', 'sanmiguelito2@aaud.gob.pa']],
        ];

        $adminAgency = null;
        foreach ($testAgencies as $agencyData) {
            $agency = Agency::firstOrCreate(
                ['name' => $agencyData['name']],
                ['code' => $agencyData['code'], 'is_active' => true]
            );
            $agency->update(['code' => $agencyData['code'], 'is_active' => true]);
            $adminAgency ??= $agency;

            foreach ($agencyData['users'] as $email) {
                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'agency_id' => $agency->id,
                        'name' => str($email)->before('@')->headline()->toString(),
                        'password' => Hash::make('aaud.123'),
                    ]
                );

                if ($user->agency_id !== $agency->id) {
                    $user->forceFill(['agency_id' => $agency->id])->save();
                }

                $user->syncRoles(['operador']);
            }

        }

        Agency::whereNotIn('name', $allowedAgencyNames)->update(['is_active' => false]);

        $admin = User::firstOrCreate(
            ['email' => 'admin@aaud.gob.pa'],
            [
                'agency_id' => $adminAgency?->id,
                'name' => 'Admin AAUD',
                'password' => Hash::make('aaud.123'),
                'is_active' => true,
            ]
        );
        if ($adminAgency && $admin->agency_id !== $adminAgency->id) {
            $admin->forceFill(['agency_id' => $adminAgency->id, 'is_active' => true])->save();
        }
        $admin->syncRoles(['admin']);

        $generalAdmin = User::firstOrCreate(
            ['email' => 'admin.general@aaud.gob.pa'],
            [
                'agency_id' => $adminAgency?->id,
                'name' => 'Administrador General AAUD',
                'password' => Hash::make('aaud.123'),
                'is_active' => true,
            ]
        );
        $generalAdmin->syncRoles(['administrador_general']);
    }
}
