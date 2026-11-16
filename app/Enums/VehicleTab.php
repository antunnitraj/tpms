<?php

namespace App\Enums;

use App\Filament\Resources\Vehicles\Pages;
use Filament\Support\Contracts\HasLabel;

/** Vehicle tabs a user can hide in Settings. Overview and Vehicle settings are always shown. */
enum VehicleTab: string implements HasLabel
{
    case Log = 'log';
    case Fuel = 'fuel';
    case Odometer = 'odometer';
    case Reminders = 'reminders';
    case Plans = 'plans';
    case Supplies = 'supplies';
    case Notes = 'notes';
    case Equipment = 'equipment';
    case Media = 'media';
    case Reports = 'reports';

    public function getLabel(): string
    {
        return match ($this) {
            self::Log => __('Log'),
            self::Fuel => __('Fuel'),
            self::Odometer => __('Odometer'),
            self::Reminders => __('Reminders'),
            self::Plans => __('Plans'),
            self::Supplies => __('Supplies'),
            self::Notes => __('Notes'),
            self::Equipment => __('Equipment'),
            self::Media => __('Photos & files'),
            self::Reports => __('Reports'),
        };
    }

    /** @return class-string */
    public function page(): string
    {
        return match ($this) {
            self::Log => Pages\ManageVehicleLog::class,
            self::Fuel => Pages\ManageVehicleFuel::class,
            self::Odometer => Pages\VehicleOdometer::class,
            self::Reminders => Pages\ManageVehicleReminders::class,
            self::Plans => Pages\ManageVehiclePlans::class,
            self::Supplies => Pages\ManageVehicleSupplies::class,
            self::Notes => Pages\ManageVehicleNotes::class,
            self::Equipment => Pages\ManageVehicleEquipment::class,
            self::Media => Pages\VehicleMedia::class,
            self::Reports => Pages\VehicleReports::class,
        };
    }

    public static function forPage(string $page): ?self
    {
        foreach (self::cases() as $tab) {
            if (is_a($page, $tab->page(), true)) {
                return $tab;
            }
        }

        return null;
    }
}
