<?php

use App\Http\Controllers\ImportPreviewFileController;
use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('/media/{uuid}/{conversion?}', MediaController::class)->name('media.show');

    Route::get('/import-preview/{import}/{path}', ImportPreviewFileController::class)
        ->where('path', '(images|documents)/[^/]+')
        ->name('import-preview.file');
});
