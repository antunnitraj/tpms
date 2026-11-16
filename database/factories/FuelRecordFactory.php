<?php

namespace Database\Factories;

use App\Models\FuelRecord;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FuelRecord> */
class FuelRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'date' => fake()->dateTimeBetween('-1 year')->format('Y-m-d'),
            'mileage' => fake()->numberBetween(1000, 200000),
            'quantity' => fake()->randomFloat(2, 20, 60),
            'cost' => fake()->randomFloat(2, 30, 100),
            'is_full' => true,
            'missed_previous' => false,
        ];
    }
}
