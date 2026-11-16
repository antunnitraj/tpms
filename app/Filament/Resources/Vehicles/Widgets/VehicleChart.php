<?php

namespace App\Filament\Resources\Vehicles\Widgets;

use App\Models\Vehicle;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Reactive;

/**
 * Shared base for vehicle report charts. Colors are fixed per category (never
 * cycled) and validated for colour-vision deficiency on light and dark surfaces.
 */
abstract class VehicleChart extends ChartWidget
{
    use ChecksVehicleAccess, RefreshesWithVehicle;

    public const COLORS = [
        'service' => '#3987e5',
        'repair' => '#d95926',
        'upgrade' => '#199e70',
        'tax_fee' => '#c98500',
        'fuel' => '#d55181',
    ];

    public const SINGLE = '#3987e5';

    public ?Model $record = null;

    #[Reactive]
    public ?int $year = null;

    /** Cost categories to count (keys of VehicleStats::costCategories()); null counts all. */
    #[Reactive]
    public ?array $categories = null;

    protected static bool $isDiscovered = false;

    // Not lazy: a chart that loads when scrolled into view would start from the filters
    // of the first render, not the year or categories picked since.
    protected static bool $isLazy = false;

    protected ?string $maxHeight = '280px';

    protected function vehicle(): Vehicle
    {
        return $this->record;
    }

    protected function baseOptions(bool $stacked = false, bool $legend = false): array
    {
        $axis = ['grid' => ['color' => 'rgba(168, 162, 158, 0.15)'], 'border' => ['display' => false], 'ticks' => ['color' => '#a8a29e']];

        return [
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => [
                'legend' => ['display' => $legend, 'position' => 'bottom', 'labels' => ['boxWidth' => 10, 'boxHeight' => 10, 'usePointStyle' => true, 'color' => '#a8a29e']],
                'tooltip' => ['enabled' => true],
            ],
            'scales' => [
                'x' => ['stacked' => $stacked, 'grid' => ['display' => false], 'border' => ['display' => false], 'ticks' => ['color' => '#a8a29e']],
                'y' => ['stacked' => $stacked, 'beginAtZero' => true] + $axis,
            ],
        ];
    }

    /** Bars: thin, 4px rounded ends, 2px gaps between stacked segments. */
    protected function bar(string $label, array $data, string $color): array
    {
        return [
            'label' => $label,
            'data' => $data,
            'backgroundColor' => $color,
            'borderColor' => 'rgba(0,0,0,0)',
            'borderWidth' => 2,
            'borderRadius' => 4,
            'borderSkipped' => false,
            'maxBarThickness' => 28,
        ];
    }

    protected function line(string $label, array $data, string $color): array
    {
        return [
            'label' => $label,
            'data' => $data,
            'borderColor' => $color,
            'backgroundColor' => $color,
            'borderWidth' => 2,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
            'tension' => 0.25,
            'spanGaps' => true,
        ];
    }
}
