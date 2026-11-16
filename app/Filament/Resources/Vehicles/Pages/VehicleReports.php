<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Enums\FuelUnit;
use App\Filament\Resources\Vehicles\Widgets\CostByCategoryChart;
use App\Filament\Resources\Vehicles\Widgets\DistanceChart;
use App\Filament\Resources\Vehicles\Widgets\FuelPriceChart;
use App\Filament\Resources\Vehicles\Widgets\FuelTrendChart;
use App\Filament\Resources\Vehicles\Widgets\MonthlyCostChart;
use App\Services\EconomyFormatter;
use App\Services\FuelEconomy;
use App\Services\Odometer;
use App\Services\VehicleStats;
use App\Support\Clock;
use Filament\Actions\Action;
use Livewire\Attributes\Url;

class VehicleReports extends VehicleRecordPage
{
    protected string $view = 'filament.vehicles.reports';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    /** A year, or "all" for all time. Empty (no ?year= in the address) means this year. */
    #[Url]
    public string $year = '';

    /** Cost categories counted in the totals and charts; remembered per user. @var list<string> */
    public array $categories = [];

    public static function getNavigationLabel(): string
    {
        return __('Reports');
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->categories = VehicleStats::selectCategories(auth()->user()->settings['report_categories'] ?? null);

        if ($this->year === '') {
            $this->year = (string) Clock::today()->year;
        }
    }

    /** The year being reported on, or null for all time. */
    public function selectedYear(): ?int
    {
        return ctype_digit($this->year) ? (int) $this->year : null;
    }

    public function updatedCategories(): void
    {
        $this->categories = VehicleStats::selectCategories($this->categories);

        $user = auth()->user();
        $user->update(['settings' => ['report_categories' => $this->categories] + ($user->settings ?? [])]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // The printable report is part of this page (shown only when printing), so it prints in place.
            Action::make('print')
                ->label(__('Print'))
                ->icon('heroicon-o-printer')->labeledFrom('sm')
                ->color('gray')
                ->alpineClickHandler('window.print()'),
        ];
    }

    public function getWidgetData(): array
    {
        return ['record' => $this->getRecord(), 'year' => $this->selectedYear(), 'categories' => $this->categories];
    }

    protected function getFooterWidgets(): array
    {
        return [MonthlyCostChart::class, CostByCategoryChart::class, DistanceChart::class, FuelTrendChart::class, FuelPriceChart::class];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return ['default' => 1, '2xl' => 2];
    }

    public function years(): array
    {
        // This year is always offered, even before anything was logged in it.
        return collect(VehicleStats::years($this->getRecord()))->push(Clock::today()->year)->unique()->sortDesc()->values()->all();
    }

    /** The table behind the charts: totals per category with per-day and per-distance figures. */
    public function breakdown(): array
    {
        $vehicle = $this->getRecord();
        $totals = VehicleStats::costByCategory($vehicle, $this->selectedYear(), $this->categories);
        $days = VehicleStats::ownershipDays($vehicle, $this->selectedYear());
        $distance = $this->selectedYear()
            ? Odometer::distanceBetween($vehicle, now()->setDate($this->selectedYear(), 1, 1), now()->setDate($this->selectedYear(), 12, 31))
            : Odometer::distanceBetween($vehicle, null, null);

        $names = VehicleStats::costCategories();

        $rows = collect($totals)->map(fn ($total, $key) => [
            'label' => $names[$key],
            'total' => $total,
            'per_day' => $days ? $total / $days : null,
            'per_distance' => $distance ? $total / $distance : null,
        ])->values()->all();

        $sum = array_sum($totals);

        return [
            'rows' => $rows,
            'total' => ['label' => __('Total'), 'total' => $sum, 'per_day' => $days ? $sum / $days : null, 'per_distance' => $distance ? $sum / $distance : null],
            'days' => $days,
            'distance' => $distance,
            'depreciation' => $this->selectedYear() ? null : VehicleStats::depreciation($vehicle),
        ];
    }

    /** What the printed report adds to the breakdown: headline numbers, fuel totals and the history by year. */
    public function printout(): array
    {
        $vehicle = $this->getRecord();
        $recordCategories = array_values(array_diff($this->categories, ['fuel']));
        $summary = VehicleStats::summary($vehicle, $this->selectedYear(), $this->categories);

        $records = $recordCategories === [] ? collect() : $vehicle->records()->with('supplyUsages.supply')
            ->whereIn('category', $recordCategories)
            ->when($this->selectedYear(), fn ($query) => $query->whereYear('date', $this->selectedYear()))
            ->orderBy('date')->orderBy('mileage')->get();

        $fuel = null;
        if (in_array('fuel', $this->categories, true)) {
            $fillUps = $vehicle->fuelRecords()->when($this->selectedYear(), fn ($query) => $query->whereYear('date', $this->selectedYear()))->get();
            $result = FuelEconomy::calculate($fillUps, $vehicle->fuelUnit() === FuelUnit::KilowattHours);
            $fuel = [
                'count' => $fillUps->count(),
                'quantity' => $result['total_quantity'],
                'cost' => $result['total_cost'],
                'unit_price' => $result['total_quantity'] > 0 ? $result['total_cost'] / $result['total_quantity'] : null,
                'economy' => EconomyFormatter::format($summary['average_economy'], $vehicle->distanceUnit(), $vehicle->fuelUnit(), auth()->user()->economyFormat()),
            ];
        }

        return [
            'summary' => $summary,
            'history' => $records->groupBy(fn ($record) => $record->date->year),
            'fuel' => $fuel,
        ];
    }
}
