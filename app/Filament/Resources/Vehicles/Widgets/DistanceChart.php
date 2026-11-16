<?php

namespace App\Filament\Resources\Vehicles\Widgets;

use App\Services\VehicleStats;

class DistanceChart extends VehicleChart
{
    public function getHeading(): string
    {
        return __('Distance driven (:unit)', ['unit' => $this->vehicle()->distanceUnit()->short()]);
    }

    protected function getData(): array
    {
        $timeline = VehicleStats::timeline($this->vehicle(), $this->year);

        return [
            'labels' => $timeline['labels'],
            'datasets' => [$this->bar(__('Distance'), $timeline['distance'], self::SINGLE)],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return $this->baseOptions();
    }
}
