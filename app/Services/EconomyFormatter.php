<?php

namespace App\Services;

use App\Enums\DistanceUnit;
use App\Enums\EconomyFormat;
use App\Enums\FuelUnit;

/** Turns a distance-per-fuel-unit ratio into the format a user prefers. */
class EconomyFormatter
{
    private const KM_PER_MILE = 1.609344;

    private const LITERS_PER_US_GALLON = 3.785411784;

    private const LITERS_PER_IMPERIAL_GALLON = 4.54609;

    /** @return array{value: float, unit: string, lower_is_better: bool}|null */
    public static function format(?float $ratio, DistanceUnit $distance, FuelUnit $fuel, EconomyFormat $format = EconomyFormat::Auto): ?array
    {
        if ($ratio === null || $ratio <= 0) {
            return null;
        }

        $format = static::resolve($format, $distance, $fuel);

        if ($format === null) {
            return static::native($ratio, $distance, $fuel);
        }

        $kmPerLiter = $ratio * $distance->toKilometers() / $fuel->toLiters();

        return match ($format) {
            EconomyFormat::LitersPer100Km => ['value' => 100 / $kmPerLiter, 'unit' => 'L/100 km', 'lower_is_better' => true],
            EconomyFormat::KmPerLiter => ['value' => $kmPerLiter, 'unit' => 'km/L', 'lower_is_better' => false],
            EconomyFormat::MpgUs => ['value' => $kmPerLiter / self::KM_PER_MILE * self::LITERS_PER_US_GALLON, 'unit' => 'mpg', 'lower_is_better' => false],
            EconomyFormat::MpgUk => ['value' => $kmPerLiter / self::KM_PER_MILE * self::LITERS_PER_IMPERIAL_GALLON, 'unit' => 'mpg (UK)', 'lower_is_better' => false],
            default => null,
        };
    }

    /** The concrete format to use, or null when only the vehicle's native units make sense. */
    public static function resolve(EconomyFormat $format, DistanceUnit $distance, FuelUnit $fuel): ?EconomyFormat
    {
        $convertible = $distance->toKilometers() !== null && $fuel->toLiters() !== null;

        if (! $convertible) {
            return null;
        }

        if ($format !== EconomyFormat::Auto) {
            return $format;
        }

        return match (true) {
            $distance === DistanceUnit::Miles && $fuel === FuelUnit::UsGallons => EconomyFormat::MpgUs,
            $distance === DistanceUnit::Miles => EconomyFormat::MpgUk,
            default => EconomyFormat::LitersPer100Km,
        };
    }

    /** Engine hours or electricity: per-100 for km/h, distance per unit for miles. */
    private static function native(float $ratio, DistanceUnit $distance, FuelUnit $fuel): array
    {
        if ($distance === DistanceUnit::Miles) {
            return ['value' => $ratio, 'unit' => $distance->short().'/'.$fuel->short(), 'lower_is_better' => false];
        }

        return ['value' => 100 / $ratio, 'unit' => $fuel->short().'/100 '.$distance->short(), 'lower_is_better' => true];
    }
}
