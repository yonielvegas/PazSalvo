<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DevelopmentBootstrapSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Bootstrap data for local development and QA only.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('DevelopmentBootstrapSeeder is prohibited in production.');
        }

        $temporaryPassword = (string) (config('seeding.temporary_password') ?: Str::password(24));
        $allowedAgencyNames = array_column(AgencyCatalog::AGENCIES, 'name');

        $usersByCode = [
            'PH-MULTIPLAZA' => ['multiplaza1@aaud.gob.pa', 'multiplaza2@aaud.gob.pa'],
            'LOS-ANDES' => ['losandes1@aaud.gob.pa', 'losandes2@aaud.gob.pa'],
            'GRAN-ESTACION' => ['granestacion1@aaud.gob.pa', 'granestacion2@aaud.gob.pa'],
            'VILLA-LUCRE' => ['villalucre1@aaud.gob.pa', 'villalucre2@aaud.gob.pa'],
            'SAN-MIGUELITO' => ['sanmiguelito1@aaud.gob.pa', 'sanmiguelito2@aaud.gob.pa'],
        ];

        $adminAgency = null;

        foreach (AgencyCatalog::AGENCIES as $agencyData) {
            $agency = Agency::firstOrCreate(
                ['name' => $agencyData['name']],
                ['code' => $agencyData['code'], 'is_active' => true]
            );
            $agency->update(['code' => $agencyData['code'], 'is_active' => true]);
            $adminAgency ??= $agency;

            foreach ($usersByCode[$agencyData['code']] as $email) {
                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'agency_id' => $agency->id,
                        'name' => str($email)->before('@')->headline()->toString(),
                        'password' => Hash::make($temporaryPassword),
                        'password_changed_at' => now(),
                    ]
                );

                if ($user->agency_id !== $agency->id) {
                    $user->forceFill(['agency_id' => $agency->id])->save();
                }

                $user->syncRoles(['operador']);
            }
        }

        // This destructive catalog operation is intentionally confined to non-production environments.
        Agency::whereNotIn('name', $allowedAgencyNames)->update(['is_active' => false]);

        $admin = User::firstOrCreate(
            ['email' => 'admin@aaud.gob.pa'],
            [
                'agency_id' => $adminAgency?->id,
                'name' => 'Admin AAUD',
                'password' => Hash::make($temporaryPassword),
                'password_changed_at' => now(),
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
                'password' => Hash::make($temporaryPassword),
                'password_changed_at' => now(),
                'is_active' => true,
            ]
        );
        $generalAdmin->syncRoles(['administrador_general']);
    }
}
