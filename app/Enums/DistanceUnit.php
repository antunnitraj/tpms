<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DistanceUnit: string implements HasLabel
{
    case Kilometers = 'km';
    case Miles = 'mi';
    case Hours = 'h';

    public function getLabel(): string
    {
        return match ($this) {
            self::Kilometers => __('Kilometers'),
            self::Miles => __('Miles'),
            self::Hours => __('Engine hours'),
        };
    }

    public function short(): string
    {
        return $this->value;
    }

    /** Factor to convert this unit to kilometers, or null when not convertible. */
    public function toKilometers(): ?float
    {
        return match ($this) {
            self::Kilometers => 1.0,
            self::Miles => 1.609344,
            self::Hours => null,
        };
    }
}
