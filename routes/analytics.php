<?php

use App\Http\Controllers\Analytics\AnalyticsController;
use Illuminate\Support\Facades\Route;

// Analytics (Phase 7C).
Route::middleware(['auth'])->group(function () {
    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('analytics/{area}/{chart}/csv', [AnalyticsController::class, 'csv'])
        ->where(['area' => '[a-z_]+', 'chart' => '[a-z_]+'])->name('analytics.csv');
    Route::post('analytics/exports/png', [AnalyticsController::class, 'png'])->name('analytics.png');
});
