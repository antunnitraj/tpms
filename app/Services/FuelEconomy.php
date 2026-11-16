<?php

namespace App\Services;

/**
 * Fuel economy from fill-ups, following LubeLogger's algorithm: partial fills
 * accumulate into the next full fill, and a missed fill-up breaks the chain.
 * The average only uses completed full-to-full segments.
 *
 * Economy values are distance per fuel unit in the vehicle's own units.
 */
class FuelEconomy
{
    /**
     * @param  iterable<object|array>  $fillUps  each with id, date, mileage, quantity, is_full, missed_previous, soc_start, soc_end
     * @return array{rows: array<int, array{distance: ?int, economy: ?float}>, average: ?float, best: ?float, worst: ?float, distance: int, fuel: float, total_quantity: float, total_cost: float}
     */
    public static function calculate(iterable $fillUps, bool $electric = false): array
    {
        $entries = collect($fillUps)->map(fn ($f) => (object) (is_array($f) ? $f : $f->toArray() + ['id' => $f->getKey()]))
            ->sortBy([
                fn ($a, $b) => strcmp(substr((string) $a->date, 0, 10), substr((string) $b->date, 0, 10)),
                fn ($a, $b) => ($a->mileage ?? PHP_INT_MAX) <=> ($b->mileage ?? PHP_INT_MAX),
                fn ($a, $b) => ($a->soc_end ?? 0) <=> ($b->soc_end ?? 0),
            ])->values();

        $rows = [];
        $segments = [];
        $previousMileage = null;
        $previousSocEnd = null;
        $accDistance = 0;
        $accFuel = 0.0;

        foreach ($entries as $entry) {
            $quantity = (float) $entry->quantity;
            $mileage = $entry->mileage === null ? null : (int) $entry->mileage;
            $row = ['distance' => null, 'economy' => null];

            if ($electric && $entry->soc_start !== null && $entry->soc_end !== null) {
                $row = static::electricRow($entry, $mileage, $previousMileage, $previousSocEnd, $quantity, $segments);
                $previousSocEnd = (int) $entry->soc_end ?: $previousSocEnd;
            } elseif ($mileage === null) {
                // No odometer: the fuel still counts towards the next completed segment,
                // unless a fill-up before it was missed: then nothing before it can be used.
                if ($entry->missed_previous) {
                    $accDistance = 0;
                    $accFuel = 0.0;
                    $previousMileage = null;
                } elseif ($previousMileage !== null) {
                    $accFuel += $quantity;
                }
            } elseif ($previousMileage === null || $entry->missed_previous) {
                // First known fill, or the previous fill-up wasn't logged: start a new chain.
                $accDistance = 0;
                $accFuel = 0.0;
                if ($previousMileage !== null) {
                    $row['distance'] = max(0, $mileage - $previousMileage);
                }
            } else {
                $delta = max(0, $mileage - $previousMileage);
                $row['distance'] = $delta;
                $accDistance += $delta;
                $accFuel += $quantity;

                if ($entry->is_full) {
                    if ($accDistance > 0 && $accFuel > 0) {
                        $row['economy'] = $accDistance / $accFuel;
                        $row['segment'] = [$accDistance, $accFuel];
                        $segments[] = [$accDistance, $accFuel];
                    }
                    $accDistance = 0;
                    $accFuel = 0.0;
                }
            }

            if ($mileage !== null) {
                $previousMileage = max($previousMileage ?? 0, $mileage);
            }

            $rows[$entry->id] = $row;
        }

        $economies = array_values(array_filter(array_column($rows, 'economy'), fn ($e) => $e !== null));
        $segmentDistance = array_sum(array_column($segments, 0));
        $segmentFuel = array_sum(array_column($segments, 1));

        return [
            'rows' => $rows,
            'average' => $segmentFuel > 0 ? $segmentDistance / $segmentFuel : null,
            'best' => $economies ? max($economies) : null,
            'worst' => $economies ? min($economies) : null,
            'distance' => (int) array_sum(array_map(fn ($r) => $r['distance'] ?? 0, $rows)),
            'fuel' => (float) $segmentFuel,
            'total_quantity' => (float) $entries->sum(fn ($e) => (float) $e->quantity),
            'total_cost' => (float) $entries->sum(fn ($e) => (float) ($e->cost ?? 0)),
        ];
    }

    /**
     * Average over the segments that end in one year. The chain is followed over the
     * whole history, so a tank filled in December still counts for the January fill-up.
     */
    public static function averageForYear(iterable $fillUps, bool $electric, int $year): ?float
    {
        $rows = static::calculate($fillUps, $electric)['rows'];

        $segments = collect($fillUps)
            ->map(fn ($f) => (object) (is_array($f) ? $f : ['id' => $f->getKey(), 'date' => $f->date]))
            ->filter(fn ($entry) => (int) substr((string) $entry->date, 0, 4) === $year)
            ->map(fn ($entry) => $rows[$entry->id]['segment'] ?? null)
            ->filter();

        $fuel = $segments->sum(fn (array $segment) => $segment[1]);

        return $fuel > 0 ? $segments->sum(fn (array $segment) => $segment[0]) / $fuel : null;
    }

    /** EV charges: energy used since the last charge is derived from the state of charge. */
    private static function electricRow(object $entry, ?int $mileage, ?int $previousMileage, ?int $previousSocEnd, float $kwh, array &$segments): array
    {
        $row = ['distance' => null, 'economy' => null];

        if ($mileage === null || $previousMileage === null) {
            return $row;
        }

        $delta = max(0, $mileage - $previousMileage);
        $row['distance'] = $delta;

        $socGain = ((int) $entry->soc_end - (int) $entry->soc_start) / 100;
        if ($entry->missed_previous || $socGain <= 0 || $previousSocEnd === null) {
            return $row;
        }

        $capacity = $kwh / $socGain;
        $consumed = max(0.0, ($previousSocEnd - (int) $entry->soc_start) / 100 * $capacity);

        if ($consumed > 0 && $delta > 0) {
            $row['economy'] = $delta / $consumed;
            $row['segment'] = [$delta, $consumed];
            $segments[] = [$delta, $consumed];
        }

        return $row;
    }
}
