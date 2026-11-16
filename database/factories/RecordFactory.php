<?php

namespace Database\Factories;

use App\Enums\RecordCategory;
use App\Models\Record;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Record> */
class RecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'category' => RecordCategory::Service,
            'date' => fake()->dateTimeBetween('-2 years')->format('Y-m-d'),
            'mileage' => fake()->numberBetween(1000, 200000),
            'description' => fake()->randomElement(['Oil change', 'Brake pads', 'Tyres', 'Registration']),
            'cost' => fake()->randomFloat(2, 10, 500),
        ];
    }
}
