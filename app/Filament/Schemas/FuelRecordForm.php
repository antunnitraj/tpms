<?php

namespace App\Filament\Schemas;

use App\Enums\FuelUnit;
use App\Models\Vehicle;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class FuelRecordForm
{
    public static function components(Vehicle $vehicle): array
    {
        $unit = $vehicle->fuelUnit();
        $electric = $unit === FuelUnit::KilowattHours;

        // Enter either the total or the unit price; the other one follows.
        $number = fn (mixed $state) => is_numeric($state = Fields::normalizeDecimal($state)) ? (float) $state : null;
        $fromUnitPrice = fn (Get $get, Set $set) => $number($get('unit_price')) !== null && $number($get('quantity')) > 0
            ? $set('cost', number_format($number($get('unit_price')) * $number($get('quantity')), 2, '.', '')) : null;
        $fromTotal = fn (Get $get, Set $set) => $number($get('cost')) !== null && $number($get('quantity')) > 0
            ? $set('unit_price', number_format($number($get('cost')) / $number($get('quantity')), 3, '.', '')) : null;

        // Typed from a receipt or the pump, like a till: 1234 is 12.34 (litres, money) and 1459 is 1.459 (price).
        return [
            Grid::make(['default' => 2])->schema([
                Fields::date(),
                Fields::mileage($vehicle),
            ]),
            Grid::make(['default' => 2, 'sm' => 3])->schema([
                Fields::decimal(TextInput::make('quantity')->label($electric ? __('Energy') : __('Amount'))
                    ->required()->minValue(0.001)->suffix($unit->short()), till: 2)
                    ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => filled($get('cost')) ? $fromTotal($get, $set) : $fromUnitPrice($get, $set)),
                Fields::money('cost', __('Total'), till: 2)->required()->live(onBlur: true)->afterStateUpdated($fromTotal),
                Fields::money('unit_price', __('Price per :unit', ['unit' => $unit->short()]), till: 3)
                    ->dehydrated(false)->live(onBlur: true)->afterStateUpdated($fromUnitPrice)
                    ->afterStateHydrated(fn (Get $get, Set $set) => $fromTotal($get, $set))
                    ->columnSpan(['default' => 2, 'sm' => 1]),
            ]),
            ...($electric ? [
                Grid::make(['default' => 2])->schema([
                    TextInput::make('soc_start')->label(__('Charge at start'))->integer()->minValue(0)->maxValue(100)->suffix('%')->default(20),
                    TextInput::make('soc_end')->label(__('Charge at end'))->integer()->minValue(0)->maxValue(100)->suffix('%')->default(80)->gte('soc_start'),
                ]),
            ] : []),
            Toggle::make('is_full')->label($electric ? __('Charged to the target level') : __('Filled the tank'))->default(true)
                ->helperText(__('Turn off for a partial fill; it counts towards the next full one.'))->visible(! $electric),
            Toggle::make('missed_previous')->label($electric ? __('I missed logging the previous charge') : __('I missed logging the previous fill-up'))->default(false)
                ->helperText(__('Economy isn\'t calculated across the gap.')),
            Fields::notes(),
            Fields::suppliesUsed($vehicle),
            Fields::attachments(),
            Section::make(__('More'))->compact()->collapsed()->schema([
                Fields::tags($vehicle, 'fuel_records'),
                Fields::links(),
            ]),
        ];
    }
}
