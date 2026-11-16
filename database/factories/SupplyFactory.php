<?php

namespace Database\Factories;

use App\Models\Supply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supply> */
class SupplyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'description' => fake()->randomElement(['Engine oil 5W-30', 'Oil filter', 'Wiper blades']),
            'part_number' => fake()->bothify('##-###-??'),
        ];
    }
}
