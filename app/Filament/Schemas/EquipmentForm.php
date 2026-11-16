<?php

namespace App\Filament\Schemas;

use App\Models\Vehicle;
use Filament\Forms\Components\TextInput;

class EquipmentForm
{
    public static function components(Vehicle $vehicle): array
    {
        return [
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255)->placeholder(__('Winter tyres')),
            Fields::notes(),
            Fields::attachments(),
            Fields::tags($vehicle, 'equipment'),
        ];
    }
}
