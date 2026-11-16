<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How fuel economy is displayed. "Auto" picks the customary format for the
 * vehicle's units; the explicit formats convert when the units allow it.
 */
enum EconomyFormat: string implements HasLabel
{
    case Auto = 'auto';
    case LitersPer100Km = 'l_per_100km';
    case KmPerLiter = 'km_per_l';
    case MpgUs = 'mpg_us';
    case MpgUk = 'mpg_uk';

    public function getLabel(): string
    {
        return match ($this) {
            self::Auto => __('Automatic (based on vehicle units)'),
            self::LitersPer100Km => 'L/100 km',
            self::KmPerLiter => 'km/L',
            self::MpgUs => 'MPG (US)',
            self::MpgUk => 'MPG (UK)',
        };
    }
}
