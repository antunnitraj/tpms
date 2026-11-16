<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Instance-wide settings (currency, timezone) chosen during setup. */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /** @var array<string, mixed>|null */
    protected static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        static::$cache ??= static::query()->pluck('value', 'key')->all();

        return array_key_exists($key, static::$cache) ? static::$cache[$key] : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        static::$cache = null;
    }

    public static function flushCache(): void
    {
        static::$cache = null;
    }

    public static function currency(): string
    {
        return (string) static::get('currency', config('tpms.currency'));
    }
}
