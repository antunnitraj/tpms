<?php

namespace App\Support;

/**
 * URLs of the app icons with a version from the file's contents. The web server
 * marks /icons/ as cacheable forever, so a changed icon needs a new address.
 */
class AppIcon
{
    /** @var array<string, string> */
    private static array $versions = [];

    public static function url(string $file): string
    {
        $version = self::$versions[$file] ??= substr((string) @md5_file(public_path("icons/{$file}")), 0, 10);

        return asset("icons/{$file}").($version !== '' ? "?v={$version}" : '');
    }
}
