<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FuelUnit: string implements HasLabel
{
    case Liters = 'l';
    case UsGallons = 'us_gal';
    case ImperialGallons = 'imp_gal';
    case KilowattHours = 'kwh';

    public function getLabel(): string
    {
        return match ($this) {
            self::Liters => __('Liters'),
            self::UsGallons => __('US gallons'),
            self::ImperialGallons => __('Imperial gallons'),
            self::KilowattHours => __('Kilowatt-hours'),
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::Liters => 'L',
            self::UsGallons => 'gal',
            self::ImperialGallons => 'imp gal',
            self::KilowattHours => 'kWh',
        };
    }

    /** Factor to convert this unit to liters, or null for energy units. */
    public function toLiters(): ?float
    {
        return match ($this) {
            self::Liters => 1.0,
            self::UsGallons => 3.785411784,
            self::ImperialGallons => 4.54609,
            self::KilowattHours => null,
        };
    }
}
