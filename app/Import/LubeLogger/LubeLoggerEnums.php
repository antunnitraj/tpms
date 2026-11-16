<?php

namespace App\Import\LubeLogger;

use App\Enums\PlanPriority;
use App\Enums\PlanStatus;
use App\Enums\RecordCategory;
use App\Enums\RepeatUnit;

/**
 * LubeLogger enum values arrive as names in LiteDB and as integers in its
 * JSON config files; these helpers accept both.
 */
class LubeLoggerEnums
{
    /** ImportMode: integer => name. */
    public const IMPORT_MODES = [
        0 => 'ServiceRecord', 1 => 'RepairRecord', 2 => 'GasRecord', 3 => 'TaxRecord', 4 => 'UpgradeRecord',
        5 => 'ReminderRecord', 6 => 'NoteRecord', 7 => 'SupplyRecord', 8 => 'Dashboard', 9 => 'PlanRecord',
        10 => 'OdometerRecord', 11 => 'VehicleRecord', 12 => 'InspectionRecord', 13 => 'EquipmentRecord',
    ];

    /** ImportMode name => LiteDB collection holding those records. */
    public const COLLECTIONS = [
        'ServiceRecord' => 'servicerecords', 'RepairRecord' => 'collisionrecords', 'GasRecord' => 'gasrecords',
        'TaxRecord' => 'taxrecords', 'UpgradeRecord' => 'upgraderecords', 'ReminderRecord' => 'reminderrecords',
        'NoteRecord' => 'notes', 'SupplyRecord' => 'supplyrecords', 'PlanRecord' => 'planrecords',
        'OdometerRecord' => 'odometerrecords', 'InspectionRecord' => 'inspectionrecords', 'EquipmentRecord' => 'equipmentrecords',
    ];

    private const MONTH_INTERVALS = [
        'Other' => 0, 'OneMonth' => 1, 'ThreeMonths' => 3, 'SixMonths' => 6, 'OneYear' => 12,
        'TwoYears' => 24, 'ThreeYears' => 36, 'FiveYears' => 60,
    ];

    private const MILEAGE_INTERVALS = [
        'Other' => 0, 'FiftyMiles' => 50, 'OneHundredMiles' => 100, 'FiveHundredMiles' => 500, 'OneThousandMiles' => 1000,
        'ThreeThousandMiles' => 3000, 'FourThousandMiles' => 4000, 'FiveThousandMiles' => 5000,
        'SevenThousandFiveHundredMiles' => 7500, 'TenThousandMiles' => 10000, 'FifteenThousandMiles' => 15000,
        'TwentyThousandMiles' => 20000, 'ThirtyThousandMiles' => 30000, 'FortyThousandMiles' => 40000,
        'FiftyThousandMiles' => 50000, 'SixtyThousandMiles' => 60000, 'OneHundredThousandMiles' => 100000,
        'OneHundredFiftyThousandMiles' => 150000,
    ];

    public static function importMode(mixed $value): ?string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return self::IMPORT_MODES[(int) $value] ?? null;
        }

        return in_array($value, self::IMPORT_MODES, true) ? $value : null;
    }

    /** Collection for a record-link location like "::ServiceRecord:12". @return array{0: string, 1: int}|null */
    public static function parseRecordLink(string $location): ?array
    {
        if (! preg_match('/^::([A-Za-z]+|\d+):(\d+)$/', $location, $m)) {
            return null;
        }

        $mode = self::importMode($m[1]);
        $collection = $mode ? (self::COLLECTIONS[$mode] ?? null) : null;

        return $collection ? [$collection, (int) $m[2]] : null;
    }

    /** Value of a numeric-valued enum (intervals) given its name or number. */
    public static function monthInterval(mixed $value): int
    {
        return self::numeric($value, self::MONTH_INTERVALS, 12);
    }

    public static function mileageInterval(mixed $value): int
    {
        return self::numeric($value, self::MILEAGE_INTERVALS, 5000);
    }

    /** ReminderIntervalUnit: Months = 1, Days = 2. */
    public static function intervalUnit(mixed $value): RepeatUnit
    {
        return ($value === 'Days' || $value === 2 || $value === '2') ? RepeatUnit::Day : RepeatUnit::Month;
    }

    /** ReminderMetric: Date = 0, Odometer = 1, Both = 2. */
    public static function metric(mixed $value): string
    {
        return match ($value) {
            'Odometer', 1, '1' => 'odometer',
            'Both', 2, '2' => 'both',
            default => 'date',
        };
    }

    public static function planStatus(mixed $value): PlanStatus
    {
        return match ($value) {
            'InProgress', 'Testing', 1, 2, '1', '2' => PlanStatus::Doing,
            'Done', 3, '3' => PlanStatus::Done,
            default => PlanStatus::Planned,
        };
    }

    public static function planPriority(mixed $value): PlanPriority
    {
        return match ($value) {
            'Critical', 0, '0' => PlanPriority::Critical,
            'Low', 2, '2' => PlanPriority::Low,
            default => PlanPriority::Normal,
        };
    }

    public static function recordCategory(mixed $value): RecordCategory
    {
        return match (self::importMode($value)) {
            'RepairRecord' => RecordCategory::Repair,
            'UpgradeRecord' => RecordCategory::Upgrade,
            default => RecordCategory::Service,
        };
    }

    private static function numeric(mixed $value, array $map, int $default): int
    {
        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return $map[$value] ?? $default;
    }
}
