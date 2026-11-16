<?php

namespace App\Services;

use App\Enums\RecordCategory;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class VehicleStats
{
    /** Everything that can cost money: the record categories plus fuel. @return array<string, string> key => label */
    public static function costCategories(): array
    {
        $labels = [];
        foreach (RecordCategory::cases() as $category) {
            $labels[$category->value] = $category->getLabel();
        }

        return $labels + ['fuel' => __('Fuel')];
    }

    /** Known cost category keys from a selection, in display order; null selects all of them. @return list<string> */
    public static function selectCategories(?array $categories): array
    {
        $keys = array_keys(static::costCategories());

        return $categories === null ? $keys : array_values(array_intersect($keys, $categories));
    }

    /** Cost per category (plus fuel), optionally for one calendar year and only the given categories. @return array<string, float> */
    public static function costByCategory(Vehicle $vehicle, ?int $year = null, ?array $categories = null): array
    {
        $records = $vehicle->records()
            ->when($year, fn ($q) => $q->whereYear('date', $year))
            ->selectRaw('category, sum(cost) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $totals = [];
        foreach (RecordCategory::cases() as $category) {
            $totals[$category->value] = (float) ($records[$category->value] ?? 0);
        }
        $totals['fuel'] = (float) $vehicle->fuelRecords()->when($year, fn ($q) => $q->whereYear('date', $year))->sum('cost');

        return array_intersect_key($totals, array_flip(static::selectCategories($categories)));
    }

    public static function totalCost(Vehicle $vehicle, ?int $year = null, ?array $categories = null): float
    {
        return array_sum(static::costByCategory($vehicle, $year, $categories));
    }

    /**
     * Monthly cost per category for a year, or yearly totals for all time.
     *
     * @return array{labels: list<string>, series: array<string, list<float>>, distance: list<int>}
     */
    public static function timeline(Vehicle $vehicle, ?int $year = null, ?array $categories = null): array
    {
        $period = $year
            ? "to_char(date, 'YYYY-MM')"
            : "to_char(date, 'YYYY')";

        $rows = DB::query()->fromSub(
            DB::table('records')->selectRaw('date, category, cost')->where('vehicle_id', $vehicle->getKey())
                ->unionAll(DB::table('fuel_records')->selectRaw("date, 'fuel' as category, cost")->where('vehicle_id', $vehicle->getKey())),
            'costs'
        )
            ->when($year, fn ($q) => $q->whereYear('date', $year))
            ->selectRaw("{$period} as period, category, sum(cost) as total")
            ->groupBy('period', 'category')
            ->get();

        if ($year) {
            $keys = collect(range(1, 12))->map(fn ($m) => sprintf('%d-%02d', $year, $m));
            $labels = $keys->map(fn ($k) => CarbonImmutable::parse($k.'-01')->translatedFormat('M'));
        } else {
            $first = (int) ($rows->min('period') ?? Clock::today()->year);
            $keys = collect(range($first, max($first, Clock::today()->year)))->map(fn ($y) => (string) $y);
            $labels = $keys;
        }

        $series = [];
        foreach (static::selectCategories($categories) as $category) {
            $series[$category] = $keys->map(fn ($k) => (float) ($rows->first(fn ($r) => $r->period === $k && $r->category === $category)?->total ?? 0))->all();
        }

        $distance = $keys->map(function ($key) use ($vehicle, $year) {
            $from = CarbonImmutable::parse($year ? $key.'-01' : $key.'-01-01');
            $to = $year ? $from->endOfMonth() : $from->endOfYear();

            return (int) (Odometer::distanceBetween($vehicle, $from, $to) ?? 0);
        })->all();

        return ['labels' => $labels->values()->all(), 'series' => $series, 'distance' => $distance];
    }

    /**
     * Headline numbers for a vehicle, optionally limited to one year. Costs count only the given categories.
     *
     * @return array{odometer: ?int, distance: ?int, total_cost: float, cost_per_distance: ?float, ownership_days: ?int, cost_per_day: ?float, average_economy: ?float}
     */
    public static function summary(Vehicle $vehicle, ?int $year = null, ?array $categories = null): array
    {
        $from = $year ? CarbonImmutable::create($year)->startOfYear() : null;
        $to = $year ? CarbonImmutable::create($year)->endOfYear() : null;

        $distance = Odometer::distanceBetween($vehicle, $from, $to);
        $total = static::totalCost($vehicle, $year, $categories);
        $days = static::ownershipDays($vehicle, $year);

        $fuel = $vehicle->fuelRecords()->get();
        $electric = $vehicle->fuelUnit()->value === 'kwh';

        return [
            'odometer' => Odometer::current($vehicle),
            'distance' => $distance,
            'total_cost' => $total,
            'cost_per_distance' => $distance ? $total / $distance : null,
            'ownership_days' => $days,
            'cost_per_day' => $days ? $total / $days : null,
            'average_economy' => $year
                ? FuelEconomy::averageForYear($fuel, $electric, $year)
                : FuelEconomy::calculate($fuel, $electric)['average'],
        ];
    }

    public static function ownershipDays(Vehicle $vehicle, ?int $year = null): ?int
    {
        $start = $vehicle->purchase_date
            ? CarbonImmutable::parse($vehicle->purchase_date->toDateString())
            : static::firstEntryDate($vehicle);
        $end = $vehicle->sold_date ? CarbonImmutable::parse($vehicle->sold_date->toDateString()) : Clock::today();

        if ($start === null) {
            return null;
        }

        if ($year) {
            $start = $start->max(CarbonImmutable::create($year)->startOfYear());
            $end = $end->min(CarbonImmutable::create($year)->endOfYear()->startOfDay());
        }

        return $end->lt($start) ? 0 : (int) $start->diffInDays($end) + 1;
    }

    public static function depreciation(Vehicle $vehicle): ?float
    {
        if ($vehicle->purchase_price === null || $vehicle->sold_price === null) {
            return null;
        }

        return (float) $vehicle->purchase_price - (float) $vehicle->sold_price;
    }

    /** @return list<int> years that have any entries, newest first */
    public static function years(Vehicle $vehicle): array
    {
        $years = DB::query()->fromSub(
            DB::table('records')->selectRaw('date')->where('vehicle_id', $vehicle->getKey())
                ->unionAll(DB::table('fuel_records')->selectRaw('date')->where('vehicle_id', $vehicle->getKey()))
                ->unionAll(DB::table('odometer_readings')->selectRaw('date')->where('vehicle_id', $vehicle->getKey())),
            'dates'
        )->selectRaw('distinct extract(year from date)::int as year')->orderByDesc('year')->pluck('year')->map(fn ($y) => (int) $y)->all();

        return $years ?: [Clock::today()->year];
    }

    private static function firstEntryDate(Vehicle $vehicle): ?CarbonImmutable
    {
        $date = collect([
            $vehicle->records()->min('date'),
            $vehicle->fuelRecords()->min('date'),
            $vehicle->odometerReadings()->min('date'),
        ])->filter()->min();

        return $date ? CarbonImmutable::parse($date) : null;
    }

    /** Next open reminder per urgency for garage cards. */
    public static function mostUrgentReminder(Vehicle $vehicle, User $user, ?int $currentMileage = null): ?array
    {
        $currentMileage ??= Odometer::current($vehicle);
        $thresholds = $user->reminderThresholds();

        return $vehicle->reminders()->open()->get()
            ->reject(fn ($reminder) => Reminders::isSnoozed($reminder))
            ->map(fn ($reminder) => [
                'reminder' => $reminder,
                'urgency' => Reminders::urgency($reminder, $currentMileage, $thresholds),
                'days' => Reminders::daysUntil($reminder),
            ])
            ->sortBy([
                fn ($a, $b) => $b['urgency']->severity() <=> $a['urgency']->severity(),
                fn ($a, $b) => ($a['days'] ?? PHP_INT_MAX) <=> ($b['days'] ?? PHP_INT_MAX),
            ])
            ->first();
    }
}
