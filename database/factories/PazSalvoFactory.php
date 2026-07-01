<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\PazSalvo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PazSalvoFactory extends Factory
{
    protected $model = PazSalvo::class;

    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 900000);

        return [
            'sequence_number' => $number, 'sequence_year' => 2026, 'folio' => sprintf('CC-%06d-2026', $number),
            'verification_token' => (string) Str::uuid(), 'generated_by' => User::factory(), 'agency_id' => Agency::factory(),
            'client_number' => fake()->numerify('#####'), 'holder_name' => fake()->name(), 'full_address' => fake()->address(),
            'total_balance' => 0, 'expired_balance' => 0, 'non_expired_balance' => 0,
            'issued_at' => now(), 'expires_at' => now()->addDays(30), 'authorized_by_name' => 'Vielsa Vergara',
            'agency_name_snapshot' => 'Agencia Central', 'generated_by_name_snapshot' => 'Operador',
            'legal_text' => config('paz-salvo.legal_text'), 'status' => PazSalvo::GENERATED,
            'certificate_snapshot' => [],
        ];
    }
}
