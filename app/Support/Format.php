<?php

namespace App\Support;

use App\Enums\DistanceUnit;
use App\Enums\FuelUnit;
use App\Models\AppSetting;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

class Format
{
    public static function money(float|int|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        return Number::currency((float) $amount, AppSetting::currency());
    }

    public static function currencySymbol(): string
    {
        $formatter = new \NumberFormatter(app()->getLocale().'@currency='.AppSetting::currency(), \NumberFormatter::CURRENCY);

        return $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL) ?: AppSetting::currency();
    }

    public static function distance(float|int|null $value, DistanceUnit $unit): string
    {
        if ($value === null) {
            return '—';
        }

        return Number::format($value, maxPrecision: 0).' '.$unit->short();
    }

    public static function quantity(float|int|string|null $value, ?FuelUnit $unit = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return trim(Number::format((float) $value, maxPrecision: 2).' '.($unit?->short() ?? ''));
    }

    /** @param  array{value: float, unit: string}|null  $economy */
    public static function economy(?array $economy): string
    {
        if ($economy === null) {
            return '—';
        }

        return Number::format($economy['value'], precision: 2).' '.$economy['unit'];
    }

    /**
     * A formatted value for a stat tile, with its unit smaller and kept on the same line:
     * "9.79 L/100 km" shows as 9.79 with "L/100 km" beside it, instead of wrapping.
     */
    public static function stat(string $formatted): HtmlString
    {
        [$number, $unit] = array_pad(explode(' ', $formatted, 2), 2, null);

        if ($unit === null || ! preg_match('/\d/', $number)) {
            return new HtmlString(e($formatted));
        }

        // Styled in theme.css (tpms-stat), so the classes don't depend on Tailwind scanning this file.
        return new HtmlString('<span class="tpms-stat">'.e($number).'<span class="tpms-stat-unit">'.e($unit).'</span></span>');
    }
}
