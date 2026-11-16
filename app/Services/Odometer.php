<?php

namespace App\Services;

use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The odometer is derived from every entry that carries a mileage: log
 * entries, fuel-ups and manual readings. Nothing is duplicated.
 */
class Odometer
{
    /** Union of all mileage-bearing rows for a vehicle: date, mileage, source, id, label. */
    public static function readingsQuery(Vehicle $vehicle): Builder
    {
        $records = DB::table('records')
            ->selectRaw("date, mileage, 'record' as source, id, description as label")
            ->where('vehicle_id', $vehicle->getKey())->whereNotNull('mileage');

        $fuel = DB::table('fuel_records')
            ->selectRaw("date, mileage, 'fuel_record' as source, id, null as label")
            ->where('vehicle_id', $vehicle->getKey())->whereNotNull('mileage');

        $readings = DB::table('odometer_readings')
            ->selectRaw("date, mileage, 'odometer_reading' as source, id, notes as label")
            ->where('vehicle_id', $vehicle->getKey());

        return DB::query()->fromSub($records->unionAll($fuel)->unionAll($readings), 'readings');
    }

    /**
     * Readings sorted chronologically, each with the distance since the previous one.
     *
     * @return Collection<int, array{date: CarbonImmutable, mileage: int, source: string, id: int, label: ?string, distance: ?int}>
     */
    public static function timeline(Vehicle $vehicle): Collection
    {
        $previous = null;

        return static::readingsQuery($vehicle)
            ->orderBy('date')->orderBy('mileage')
            ->get()
            ->map(function ($row) use (&$previous) {
                $mileage = (int) $row->mileage;
                $entry = [
                    'date' => CarbonImmutable::parse($row->date),
                    'mileage' => $mileage,
                    'source' => $row->source,
                    'id' => (int) $row->id,
                    'label' => $row->label,
                    'distance' => $previous === null ? null : max(0, $mileage - $previous),
                ];
                $previous = max($previous ?? 0, $mileage);

                return $entry;
            });
    }

    public static function current(Vehicle $vehicle): ?int
    {
        $max = static::readingsQuery($vehicle)->max('mileage');

        return $max === null ? null : (int) $max;
    }

    public static function first(Vehicle $vehicle): ?int
    {
        $min = static::readingsQuery($vehicle)->min('mileage');

        return $min === null ? null : (int) $min;
    }

    /** Highest mileage recorded on or before the given date. */
    public static function atDate(Vehicle $vehicle, CarbonInterface $date): ?int
    {
        $max = static::readingsQuery($vehicle)->where('date', '<=', $date->toDateString())->max('mileage');

        return $max === null ? null : (int) $max;
    }

    /** Distance driven between two dates (inclusive), based on the readings around them. */
    public static function distanceBetween(Vehicle $vehicle, ?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        $query = static::readingsQuery($vehicle);

        $end = $to
            ? (clone $query)->where('date', '<=', $to->toDateString())->max('mileage')
            : (clone $query)->max('mileage');

        $start = $from
            ? ((clone $query)->where('date', '<', $from->toDateString())->max('mileage')
                ?? (clone $query)->where('date', '>=', $from->toDateString())->min('mileage'))
            : (clone $query)->min('mileage');

        if ($end === null || $start === null) {
            return null;
        }

        return max(0, (int) $end - (int) $start);
    }
}
