<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\DebtQuery;
use Illuminate\Database\Eloquent\Factories\Factory;

class DebtQueryFactory extends Factory
{
    protected $model = DebtQuery::class;

    public function definition(): array
    {
        return ['client_id' => Client::factory(), 'client_number' => fake()->numerify('#####'), 'status' => DebtQuery::DEBT_FREE, 'total_balance' => 0, 'raw_response' => [], 'queried_at' => now()];
    }
}
