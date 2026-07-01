<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return ['client_number' => fake()->unique()->numerify('#####'), 'holder_name' => fake()->name(), 'is_active' => true];
    }
}
