<?php

namespace App\Filament\Actions;

use App\Filament\Schemas\Fields;
use App\Models\Supply;
use App\Models\SupplyMovement;
use App\Services\Supplies;
use App\Support\Clock;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;

class SupplyActions
{
    public static function purchase(): Action
    {
        return Action::make('purchase')
            ->label(__('Restock'))
            ->icon('heroicon-o-shopping-cart')
            ->color('success')
            ->modalWidth('md')
            ->schema([
                Fields::date(),
                Grid::make(['default' => 2])->schema([
                    TextInput::make('quantity')->label(__('Quantity'))->numeric()->inputMode('decimal')->required()->minValue(0.001),
                    Fields::money('cost', __('Total paid'))->required()->default(0),
                ]),
            ])
            ->action(fn (Supply $record, array $data) => Supplies::purchase($record, $data['quantity'], $data['cost'], CarbonImmutable::parse($data['date'])))
            ->modalSubmitActionLabel(__('Restock'))
            ->successNotificationTitle(__('Restocked'));
    }

    public static function adjust(): Action
    {
        return Action::make('adjust')
            ->label(__('Correct stock'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('gray')
            ->modalWidth('md')
            ->modalDescription(__('Use a negative amount for spilled, lost or used-up stock.'))
            ->schema([
                Grid::make(['default' => 2])->schema([
                    TextInput::make('quantity')->label(__('Change in quantity'))->numeric()->inputMode('decimal')->required(),
                    Fields::money('cost', __('Change in value'))->default(0),
                ]),
                TextInput::make('note')->label(__('Reason'))->maxLength(255),
            ])
            ->action(fn (Supply $record, array $data) => Supplies::adjust($record, $data['quantity'], $data['cost'] ?: 0, Clock::today(), $data['note'] ?? null))
            ->modalSubmitActionLabel(__('Correct stock'))
            ->successNotificationTitle(__('Stock corrected'));
    }

    public static function history(): Action
    {
        return Action::make('history')
            ->label(__('History'))
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->slideOver()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->schema([
                RepeatableEntry::make('movements')
                    ->hiddenLabel()
                    ->state(fn (Supply $record) => $record->movements()->with('consumer')->orderByDesc('date')->orderByDesc('id')->get())
                    ->schema([
                        TextEntry::make('summary')->hiddenLabel()
                            ->state(fn (SupplyMovement $record) => collect([
                                $record->date->toFormattedDateString(),
                                $record->type->getLabel(),
                                $record->consumer?->description ?? ($record->consumer ? __('Fuel-up') : null),
                                $record->note,
                            ])->filter()->implode(' · ')),
                        TextEntry::make('amount')->hiddenLabel()->alignEnd()
                            ->state(fn (SupplyMovement $record) => sprintf('%+g', (float) $record->quantity).' · '.Format::money($record->cost)),
                    ])
                    ->columns(['default' => 2])
                    ->contained(false),
            ]);
    }
}
