<?php

namespace App\Filament\Resources\Vehicles\Widgets;

use App\Services\VehicleStats;

class MonthlyCostChart extends VehicleChart
{
    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return $this->year ? __('Spending per month') : __('Spending per year');
    }

    protected function getData(): array
    {
        $timeline = VehicleStats::timeline($this->vehicle(), $this->year, $this->categories);
        $names = VehicleStats::costCategories();

        $datasets = [];
        foreach ($timeline['series'] as $category => $values) {
            if (array_sum($values) > 0) {
                $datasets[] = $this->bar($names[$category], $values, self::COLORS[$category]);
            }
        }

        return ['datasets' => $datasets, 'labels' => $timeline['labels']];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return $this->baseOptions(stacked: true, legend: true);
    }
}
