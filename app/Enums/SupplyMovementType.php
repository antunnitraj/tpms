<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SupplyMovementType: string implements HasColor, HasLabel
{
    case Purchase = 'purchase';
    case Use = 'use';
    case Adjust = 'adjust';

    public function getLabel(): string
    {
        return match ($this) {
            self::Purchase => __('Purchase'),
            self::Use => __('Used'),
            self::Adjust => __('Adjustment'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Purchase => 'success',
            self::Use => 'warning',
            self::Adjust => 'gray',
        };
    }
}
