<?php

namespace App\Filament\Resources\Vehicles\Widgets;

use App\Support\Format;

class FuelPriceChart extends VehicleChart
{
    public function getHeading(): string
    {
        return __('Fuel price (:currency/:unit)', ['currency' => Format::currencySymbol(), 'unit' => $this->vehicle()->fuelUnit()->short()]);
    }

    protected function getData(): array
    {
        $points = $this->vehicle()->fuelRecords()
            ->when($this->year, fn ($q) => $q->whereYear('date', $this->year))
            ->where('quantity', '>', 0)
            ->orderBy('date')
            ->get()
            ->map(fn ($f) => ['label' => $f->date->toFormattedDateString(), 'value' => round($f->unitPrice(), 3)]);

        return [
            'labels' => $points->pluck('label')->all(),
            'datasets' => [$this->line(__('Price'), $points->pluck('value')->all(), self::SINGLE)],
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
