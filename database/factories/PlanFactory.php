<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'description' => fake()->randomElement(['Replace wipers', 'Timing belt', 'New speakers']),
            'category' => 'service',
            'priority' => 'normal',
            'status' => 'planned',
            'cost' => fake()->randomFloat(2, 10, 300),
        ];
    }
}
