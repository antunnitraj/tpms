<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum RecordCategory: string implements HasColor, HasIcon, HasLabel
{
    case Service = 'service';
    case Repair = 'repair';
    case Upgrade = 'upgrade';
    case TaxFee = 'tax_fee';

    public function getLabel(): string
    {
        return match ($this) {
            self::Service => __('Service'),
            self::Repair => __('Repair'),
            self::Upgrade => __('Upgrade'),
            self::TaxFee => __('Tax & fees'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Service => 'info',
            self::Repair => 'danger',
            self::Upgrade => 'success',
            self::TaxFee => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Service => 'heroicon-o-wrench-screwdriver',
            self::Repair => 'heroicon-o-wrench',
            self::Upgrade => 'heroicon-o-sparkles',
            self::TaxFee => 'heroicon-o-receipt-percent',
        };
    }

    /** Categories a plan can turn into once it is done. */
    public static function plannable(): array
    {
        return [self::Service, self::Repair, self::Upgrade];
    }
}
