<?php

namespace App\Filament\Resources\Vehicles\Widgets;

use App\Services\VehicleStats;

/** Totals per category as horizontal bars (easier to compare than a pie). */
class CostByCategoryChart extends VehicleChart
{
    protected ?string $maxHeight = '220px';

    public function getHeading(): string
    {
        return __('Where the money goes');
    }

    protected function getData(): array
    {
        $totals = VehicleStats::costByCategory($this->vehicle(), $this->year, $this->categories);
        $names = VehicleStats::costCategories();

        return [
            'labels' => array_values(array_map(fn ($k) => $names[$k], array_keys($totals))),
            'datasets' => [[
                ...$this->bar(__('Cost'), array_values($totals), self::SINGLE),
                'backgroundColor' => array_values(array_map(fn ($k) => self::COLORS[$k], array_keys($totals))),
            ]],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        $options = $this->baseOptions();
        $options['indexAxis'] = 'y';
        $options['scales']['x'] = $options['scales']['y'];
        $options['scales']['y'] = ['grid' => ['display' => false], 'border' => ['display' => false], 'ticks' => ['color' => '#a8a29e']];

        return $options;
    }
}
