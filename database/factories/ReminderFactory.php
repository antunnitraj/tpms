<?php

namespace Database\Factories;

use App\Models\Reminder;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reminder> */
class ReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'description' => fake()->randomElement(['Oil change', 'Insurance', 'Inspection']),
            'due_date' => now()->addMonths(3)->toDateString(),
        ];
    }
}
