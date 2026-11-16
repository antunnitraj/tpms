<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Wipes the instance back to a fresh install: every table except the migration
 * history (users, vehicles, settings, sessions, queued jobs…), every stored
 * photo and document, and unfinished import uploads. The next visit opens Setup.
 */
class InstanceReset
{
    public static function run(): void
    {
        $tables = collect(Schema::getTables(schema: DB::connection()->getConfig('search_path') ?: 'public'))
            ->pluck('name')
            ->reject(fn (string $table) => $table === 'migrations')
            ->values();

        if ($tables->isNotEmpty()) {
            DB::statement('truncate table '.$tables->map(fn (string $table) => '"'.$table.'"')->implode(', ').' restart identity cascade');
        }

        static::emptyDirectory(Storage::disk('media')->path(''));
        static::emptyDirectory(storage_path('app/private/imports'));

        Cache::flush();
        AppSetting::flushCache();
    }

    /** Deletes everything inside, except dotfiles such as .gitignore. */
    private static function emptyDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (File::directories($path) as $directory) {
            File::deleteDirectory($directory);
        }

        File::delete(File::files($path));
    }
}
