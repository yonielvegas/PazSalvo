<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Client;
use App\Models\GeneralAdminSignature;
use App\Models\PazSalvo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class PazSalvoFactory extends Factory
{
    protected $model = PazSalvo::class;

    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 900000);
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        Role::firstOrCreate(['name' => 'administrador_general', 'guard_name' => 'web']);
        $generalAdmin = User::factory()->create(['is_active' => true]);
        $generalAdmin->syncRoles(['administrador_general']);
        $generalAdminSignature = GeneralAdminSignature::create([
            'user_id' => $generalAdmin->id,
            'signature_path' => 'templates/assets/Firma.jpeg',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        return [
            'sequence_number' => $number, 'sequence_year' => 2026, 'folio' => sprintf('CC-%06d-2026', $number),
            'verification_token' => (string) Str::uuid(), 'client_id' => Client::factory(),
            'generated_by' => $user->id, 'agency_id' => $agency->id,
            'general_admin_signature_id' => $generalAdminSignature->id,
            'total_balance' => 0, 'issued_at' => now(), 'expires_at' => now()->addDays(30), 'status' => PazSalvo::GENERATED,
        ];
    }
}
