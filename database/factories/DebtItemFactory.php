<?php

namespace Database\Factories;

use App\Models\DebtItem;
use App\Models\DebtQuery;
use Illuminate\Database\Eloquent\Factories\Factory;

class DebtItemFactory extends Factory
{
    protected $model = DebtItem::class;

    public function definition(): array
    {
        return ['debt_query_id' => DebtQuery::factory(), 'period' => 'JUN/2026', 'amount' => fake()->randomFloat(2, 1, 100)];
    }
}
