<?php

use App\Http\Controllers\Exports\ListExportController;
use Illuminate\Support\Facades\Route;

// List exports (Phase 11E): every list page's Export button. Files are
// generated as report runs and downloaded from "My reports".
Route::middleware(['auth', 'throttle:reports'])->group(function () {
    Route::post('exports/{list}/preview', [ListExportController::class, 'preview'])
        ->where('list', '[a-z_]+')
        ->name('exports.preview');
    Route::post('exports', [ListExportController::class, 'store'])->name('exports.store');
});
