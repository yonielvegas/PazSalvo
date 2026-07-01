<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Client;
use App\Models\PazSalvo;
use App\Models\User;
use App\Models\UserSignature;
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
        Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
        $supervisor = User::factory()->create(['agency_id' => $agency->id, 'is_active' => true]);
        $supervisor->syncRoles(['supervisor']);
        $signature = UserSignature::create([
            'user_id' => $supervisor->id,
            'agency_id' => $agency->id,
            'signature_path' => 'templates/assets/Firma.jpeg',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        return [
            'sequence_number' => $number, 'sequence_year' => 2026, 'folio' => sprintf('CC-%06d-2026', $number),
            'verification_token' => (string) Str::uuid(), 'client_id' => Client::factory(),
            'generated_by' => $user->id, 'agency_id' => $agency->id, 'user_signature_id' => $signature->id,
            'total_balance' => 0, 'issued_at' => now(), 'expires_at' => now()->addDays(30), 'status' => PazSalvo::GENERATED,
        ];
    }
}
