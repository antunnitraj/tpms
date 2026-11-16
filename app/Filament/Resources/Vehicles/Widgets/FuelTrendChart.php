<?php

namespace App\Filament\Resources\Vehicles\Widgets;

use App\Enums\FuelUnit;
use App\Services\EconomyFormatter;
use App\Services\FuelEconomy;

/** Fuel economy per full fill-up over time. */
class FuelTrendChart extends VehicleChart
{
    public function getHeading(): string
    {
        $vehicle = $this->vehicle();
        $unit = EconomyFormatter::format(1.0, $vehicle->distanceUnit(), $vehicle->fuelUnit(), auth()->user()->economyFormat())['unit'] ?? '';

        // "Fuel economy · mpg (UK)", not "Fuel economy (mpg (UK))".
        return $unit === '' ? __('Fuel economy') : __('Fuel economy · :unit', ['unit' => $unit]);
    }

    protected function getData(): array
    {
        $vehicle = $this->vehicle();
        $fuel = $vehicle->fuelRecords()->orderBy('date')->orderBy('mileage')->get();
        $rows = FuelEconomy::calculate($fuel, $vehicle->fuelUnit() === FuelUnit::KilowattHours)['rows'];
        $format = auth()->user()->economyFormat();

        $points = $fuel
            ->filter(fn ($f) => ! $this->year || $f->date->year === $this->year)
            ->filter(fn ($f) => ($rows[$f->getKey()]['economy'] ?? null) !== null)
            ->map(fn ($f) => [
                'label' => $f->date->toFormattedDateString(),
                'value' => round(EconomyFormatter::format($rows[$f->getKey()]['economy'], $vehicle->distanceUnit(), $vehicle->fuelUnit(), $format)['value'], 2),
            ])->values();

        return [
            'labels' => $points->pluck('label')->all(),
            'datasets' => [$this->line(__('Economy'), $points->pluck('value')->all(), self::SINGLE)],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        $options = $this->baseOptions();
        $options['scales']['y']['beginAtZero'] = false;

        return $options;
    }
}
