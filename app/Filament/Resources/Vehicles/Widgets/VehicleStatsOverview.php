<?php

namespace App\Filament\Resources\Vehicles\Widgets;

use App\Models\Vehicle;
use App\Services\EconomyFormatter;
use App\Services\VehicleStats;
use App\Support\Format;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Reactive;

class VehicleStatsOverview extends StatsOverviewWidget
{
    use ChecksVehicleAccess, RefreshesWithVehicle;

    public ?Model $record = null;

    #[Reactive]
    public ?int $year = null;

    /** Cost categories to count (keys of VehicleStats::costCategories()); null counts all. */
    #[Reactive]
    public ?array $categories = null;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        // The vehicle menu sits beside the content from md up; four roomy tiles only fit from 2xl.
        return ['default' => 2, '2xl' => 4];
    }

    protected function getStats(): array
    {
        /** @var Vehicle $vehicle */
        $vehicle = $this->record;
        $summary = VehicleStats::summary($vehicle, $this->year, $this->categories);
        $unit = $vehicle->distanceUnit();
        $economy = EconomyFormatter::format($summary['average_economy'], $unit, $vehicle->fuelUnit(), auth()->user()->economyFormat());

        return [
            Stat::make($this->year ? __('Distance in :year', ['year' => $this->year]) : __('Odometer'),
                Format::stat($this->year ? Format::distance($summary['distance'], $unit) : Format::distance($summary['odometer'], $unit)))
                ->description($this->year ? null : ($summary['distance'] ? __(':distance tracked', ['distance' => Format::distance($summary['distance'], $unit)]) : null)),
            Stat::make(__('Total cost'), Format::money($summary['total_cost']))
                ->description($summary['cost_per_day'] ? __(':amount per day', ['amount' => Format::money($summary['cost_per_day'])]) : null),
            Stat::make(__('Cost per :unit', ['unit' => $unit->short()]), $summary['cost_per_distance'] !== null ? Format::money($summary['cost_per_distance']) : '—'),
            Stat::make(__('Fuel economy'), Format::stat(Format::economy($economy)))
                ->description($economy ? __('average') : __('needs two full fill-ups')),
        ];
    }
}
