<?php

namespace App\Support;

use App\Enums\FuelUnit;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\EconomyFormatter;
use App\Services\FuelEconomy;
use App\Services\Odometer;
use App\Services\VehicleStats;

/** Numbers shown on a garage card. */
class VehicleCard
{
    public static function for(Vehicle $vehicle, User $user): array
    {
        $odometer = Odometer::current($vehicle);
        $first = Odometer::first($vehicle);
        $total = VehicleStats::totalCost($vehicle);
        $distance = $odometer !== null && $first !== null ? $odometer - $first : null;

        $economy = FuelEconomy::calculate($vehicle->fuelRecords()->get(), $vehicle->fuelUnit() === FuelUnit::KilowattHours)['average'];

        return [
            'odometer' => $odometer,
            'total_cost' => $total,
            'year_cost' => VehicleStats::totalCost($vehicle, Clock::today()->year),
            'cost_per_distance' => $distance ? $total / $distance : null,
            'economy' => EconomyFormatter::format($economy, $vehicle->distanceUnit(), $vehicle->fuelUnit(), $user->economyFormat()),
            'reminder' => VehicleStats::mostUrgentReminder($vehicle, $user, $odometer),
        ];
    }
}
