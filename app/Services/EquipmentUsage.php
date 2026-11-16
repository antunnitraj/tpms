<?php

namespace App\Services;

use App\Models\Equipment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class EquipmentUsage
{
    /** Distance driven with the equipment installed; an open period runs to the current odometer. */
    public static function distance(Equipment $equipment, ?int $currentMileage = null): int
    {
        $currentMileage ??= Odometer::current($equipment->vehicle);

        return (int) $equipment->periods->sum(function ($period) use ($currentMileage) {
            $end = $period->removed_on ? $period->removed_mileage : $currentMileage;

            if ($period->installed_mileage === null || $end === null) {
                return 0;
            }

            return max(0, $end - $period->installed_mileage);
        });
    }

    public static function install(Equipment $equipment, CarbonInterface $date, ?int $mileage): void
    {
        DB::transaction(function () use ($equipment, $date, $mileage) {
            if ($equipment->openPeriod()->exists()) {
                return;
            }

            $equipment->periods()->create([
                'installed_on' => $date->toDateString(),
                'installed_mileage' => $mileage,
            ]);
        });
    }

    public static function remove(Equipment $equipment, CarbonInterface $date, ?int $mileage): void
    {
        $equipment->periods()->whereNull('removed_on')->update([
            'removed_on' => $date->toDateString(),
            'removed_mileage' => $mileage,
        ]);
    }
}
