<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class ProductionBootstrapSeeder extends Seeder
{
    use WithoutModelEvents;

    private const IT_ADMIN_EMAIL = 'itadmin@aaud.gob.pa';

    private const INITIAL_PASSWORD_HASH = '$2y$12$t9Vr4UdoVG5Pjuq/mk.U8.vK/TwJI3DUZBDs3vWQCt/LylOzFVZ.K';

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call(MasterDataSeeder::class);

            foreach (AgencyCatalog::AGENCIES as $data) {
                // Match legacy rows by name when their code has not been populated.
                $agency = Agency::where('code', $data['code'])->first()
                    ?? Agency::where('name', $data['name'])->first();
                if (! $agency) {
                    Agency::create([...$data, 'is_active' => true]);
                } elseif ($agency->code === null) {
                    $agency->update(['code' => $data['code']]);
                } elseif ($agency->code !== $data['code']) {
                    throw new \RuntimeException("Conflicting code for agency {$data['name']}.");
                }
            }

            $agency = Agency::where('code', AgencyCatalog::IT_ADMIN_AGENCY_CODE)->firstOrFail();
            $role = Role::where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
            if (! filter_var($role->is_active, FILTER_VALIDATE_BOOLEAN)) {
                $role->forceFill(['is_active' => true])->save();
            }
            $user = User::firstOrCreate(['email' => self::IT_ADMIN_EMAIL], [
                'name' => 'IT Admin',
                'password' => self::INITIAL_PASSWORD_HASH,
                'agency_id' => $agency->id,
                'email_verified_at' => now(),
                'password_changed_at' => now(),
                'is_active' => true,
                'is_login_blocked' => false,
                'login_attempts' => 0,
                'must_change_password' => false,
                'session_version' => 0,
            ]);

            $user->forceFill([
                'name' => 'IT Admin',
                'agency_id' => $agency->id,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'is_active' => true,
                'is_login_blocked' => false,
                'login_attempts' => 0,
                'must_change_password' => false,
                'session_version' => $user->session_version ?? 0,
            ])->save();
            $user->assignRole($role);
        });
    }
}
