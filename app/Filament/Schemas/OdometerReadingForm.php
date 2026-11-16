<?php

namespace App\Filament\Schemas;

use App\Models\Vehicle;
use Filament\Schemas\Components\Grid;

class OdometerReadingForm
{
    public static function components(Vehicle $vehicle): array
    {
        return [
            Grid::make(['default' => 2])->schema([
                Fields::date(),
                Fields::mileage($vehicle, required: true)->default(null),
            ]),
            Fields::notes(),
            Fields::tags($vehicle, 'odometer_readings'),
        ];
    }
}
