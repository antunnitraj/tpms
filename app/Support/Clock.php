<?php

namespace App\Support;

use App\Models\AppSetting;
use Carbon\CarbonImmutable;

/** "Today" in the instance's display timezone, so due dates flip at local midnight. */
class Clock
{
    public static function timezone(): string
    {
        return (string) AppSetting::get('timezone', config('app.timezone'));
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(static::timezone())->startOfDay();
    }
}
