<?php

namespace Database\Factories;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'year' => fake()->numberBetween(1995, 2025),
            'make' => fake()->randomElement(['Toyota', 'Volkswagen', 'Ford', 'Renault', 'Mazda']),
            'model' => fake()->randomElement(['Corolla', 'Golf', 'Focus', 'Clio', '3']),
            'license_plate' => strtoupper(fake()->bothify('??###??')),
            'fuel_type' => 'gasoline',
            'distance_unit' => 'km',
            'fuel_unit' => 'l',
        ];
    }
}
