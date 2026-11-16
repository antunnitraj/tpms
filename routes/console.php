<?php

use App\Import\LubeLogger\LubeLoggerImporter;
use Illuminate\Support\Facades\Schedule;

Schedule::command('reminders:check')->hourly();

// Uploaded backups that were previewed but never imported.
Schedule::call(fn () => app(LubeLoggerImporter::class)->cleanupStale())->daily()->name('imports:cleanup');

Schedule::command('media-library:clean --force')->weekly();
