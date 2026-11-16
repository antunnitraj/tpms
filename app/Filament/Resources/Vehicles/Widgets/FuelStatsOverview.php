<?php

namespace App\Filament\Resources\Vehicles\Widgets;

use App\Enums\FuelUnit;
use App\Models\Vehicle;
use App\Services\EconomyFormatter;
use App\Services\FuelEconomy;
use App\Support\Format;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

class FuelStatsOverview extends StatsOverviewWidget
{
    use ChecksVehicleAccess, RefreshesWithVehicle;

    public ?Model $record = null;

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
        $fuelUnit = $vehicle->fuelUnit();
        $distanceUnit = $vehicle->distanceUnit();
        $format = auth()->user()->economyFormat();
        $result = FuelEconomy::calculate($vehicle->fuelRecords()->get(), $fuelUnit === FuelUnit::KilowattHours);

        // Ratios are distance per unit, so the highest ratio is the best in every display format.
        $average = EconomyFormatter::format($result['average'], $distanceUnit, $fuelUnit, $format);
        $best = EconomyFormatter::format($result['best'], $distanceUnit, $fuelUnit, $format);
        $worst = EconomyFormatter::format($result['worst'], $distanceUnit, $fuelUnit, $format);

        return [
            Stat::make(__('Average economy'), Format::stat(Format::economy($average)))
                ->description($best && $worst ? __('best :best · worst :worst', ['best' => Format::economy($best), 'worst' => Format::economy($worst)]) : null),
            Stat::make(__('Distance'), Format::stat(Format::distance($result['distance'], $distanceUnit))),
            Stat::make($fuelUnit === FuelUnit::KilowattHours ? __('Energy') : __('Fuel'), Format::stat(Format::quantity($result['total_quantity'], $fuelUnit))),
            Stat::make($fuelUnit === FuelUnit::KilowattHours ? __('Charging cost') : __('Fuel cost'), Format::money($result['total_cost']))
                ->description($result['total_quantity'] > 0 ? __(':price average', ['price' => Format::money($result['total_cost'] / $result['total_quantity']).'/'.$fuelUnit->short()]) : null),
        ];
    }
}
