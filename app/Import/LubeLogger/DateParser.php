<?php

namespace App\Import\LubeLogger;

/**
 * LubeLogger stores a few dates as culture-formatted strings (e.g. vehicle
 * purchase dates "31. 12. 2024." or "5/2/2025"). This parses them given a
 * day/month/year order and detects that order from the values themselves.
 */
class DateParser
{
    public const ORDERS = ['dmy', 'mdy', 'ymd'];

    public static function parse(?string $value, string $order): ?string
    {
        if ($value === null || ! preg_match('/(\d{1,4})\D+(\d{1,2})\D+(\d{1,4})/', $value, $m)) {
            return null;
        }

        [$a, $b, $c] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if (strlen($m[1]) === 4) {
            $order = 'ymd';
        }

        [$year, $month, $day] = match ($order) {
            'ymd' => [$a, $b, $c],
            'mdy' => [$c, $a, $b],
            default => [$c, $b, $a],
        };

        if ($year < 100) {
            $year += $year < 70 ? 2000 : 1900;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /** @param  list<string>  $samples */
    public static function detectOrder(array $samples, ?string $locale = null): string
    {
        $dotted = false;

        foreach ($samples as $sample) {
            if (! preg_match('/(\d{1,4})(\D+)(\d{1,2})\D+(\d{1,4})/', $sample, $m)) {
                continue;
            }

            if (strlen($m[1]) === 4) {
                return 'ymd';
            }
            if ((int) $m[1] > 12) {
                return 'dmy';
            }
            if ((int) $m[3] > 12) {
                return 'mdy';
            }
            $dotted = $dotted || str_contains($m[2], '.');
        }

        if ($dotted) {
            return 'dmy';
        }

        if ($locale !== null && $locale !== '') {
            $locale = str_replace('_', '-', strtolower($locale));

            return match (true) {
                in_array($locale, ['en-us', 'en-ph', 'en-ca', 'es-us', 'fil-ph'], true) => 'mdy',
                str_starts_with($locale, 'zh') || str_starts_with($locale, 'ja') || str_starts_with($locale, 'ko')
                    || str_starts_with($locale, 'hu') || str_starts_with($locale, 'lt') || $locale === 'sv-se' => 'ymd',
                default => 'dmy',
            };
        }

        // LubeLogger's default culture is en-US.
        return 'mdy';
    }
}
