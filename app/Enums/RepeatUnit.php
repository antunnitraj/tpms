<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RepeatUnit: string implements HasLabel
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function getLabel(): string
    {
        return match ($this) {
            self::Day => __('Days'),
            self::Week => __('Weeks'),
            self::Month => __('Months'),
            self::Year => __('Years'),
        };
    }
}
