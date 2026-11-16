<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FuelType: string implements HasLabel
{
    case Gasoline = 'gasoline';
    case Diesel = 'diesel';
    case Electric = 'electric';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Gasoline => __('Gasoline'),
            self::Diesel => __('Diesel'),
            self::Electric => __('Electric'),
            self::Other => __('Other'),
        };
    }
}
