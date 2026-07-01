<?php

namespace Database\Factories;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgencyFactory extends Factory
{
    protected $model = Agency::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->company(), 'code' => fake()->unique()->lexify('AG-???'), 'is_active' => true];
    }
}
