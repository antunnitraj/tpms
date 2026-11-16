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
        $fromUnitPrice = fn (Get $get, Set $set) => filled($get('unit_price')) && (float) $get('quantity') > 0
            ? $set('cost', round((float) $get('unit_price') * (float) $get('quantity'), 2)) : null;
        $fromTotal = fn (Get $get, Set $set) => (float) $get('quantity') > 0 && filled($get('cost'))
            ? $set('unit_price', round((float) $get('cost') / (float) $get('quantity'), 3)) : null;

        return [
            Grid::make(['default' => 2])->schema([
                Fields::date(),
                Fields::mileage($vehicle),
            ]),
            Grid::make(['default' => 2, 'sm' => 3])->schema([
                TextInput::make('quantity')->label($electric ? __('Energy') : __('Amount'))
                    ->numeric()->inputMode('decimal')->required()->minValue(0.001)->suffix($unit->short())
                    ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => filled($get('unit_price')) ? $fromUnitPrice($get, $set) : $fromTotal($get, $set)),
                Fields::money('unit_price', __('Price per :unit', ['unit' => $unit->short()]))
                    ->step('0.001')->dehydrated(false)->live(onBlur: true)->afterStateUpdated($fromUnitPrice)
                    ->afterStateHydrated(fn (Get $get, Set $set) => $fromTotal($get, $set)),
                Fields::money('cost', __('Total'))->required()->live(onBlur: true)->afterStateUpdated($fromTotal)
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
