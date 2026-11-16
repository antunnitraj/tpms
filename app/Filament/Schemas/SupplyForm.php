<?php

namespace App\Filament\Schemas;

use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

class SupplyForm
{
    public static function components(bool $creating = true): array
    {
        return [
            TextInput::make('description')->label(__('Description'))->required()->maxLength(255)->placeholder(__('Engine oil 5W-30')),
            Grid::make(['default' => 2])->schema([
                TextInput::make('part_number')->label(__('Part number'))->maxLength(100),
                TextInput::make('supplier')->label(__('Supplier'))->maxLength(100),
            ]),
            ...($creating ? [
                Section::make(__('In stock'))->compact()->columns(['default' => 2])->schema([
                    TextInput::make('initial_quantity')->label(__('Quantity'))->numeric()->inputMode('decimal')->minValue(0)->default(1),
                    Fields::money('initial_cost', __('Total paid'))->default(0),
                ]),
            ] : []),
            Fields::notes(),
            Fields::attachments(),
            TagsInput::make('tags')->label(__('Tags')),
        ];
    }
}
