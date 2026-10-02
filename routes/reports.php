<?php

use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportScheduleController;
use Illuminate\Support\Facades\Route;

// Reports and exports (Phase 7D).
Route::middleware(['auth'])->group(function () {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('reports', [ReportController::class, 'store'])->middleware('throttle:reports')->name('reports.store');
    Route::post('reports/estimate', [ReportController::class, 'estimate'])->middleware('throttle:reports')->name('reports.estimate');
    Route::get('reports/runs/{run}/download', [ReportController::class, 'download'])->name('reports.download');

    Route::get('reports/schedules', [ReportScheduleController::class, 'index'])->name('reports.schedules.index');
    Route::post('reports/schedules', [ReportScheduleController::class, 'store'])->name('reports.schedules.store');
    Route::post('reports/schedules/defaults/{key}', [ReportScheduleController::class, 'storeDefault'])
        ->where('key', '[a-z_]+')->name('reports.schedules.default');
    Route::patch('reports/schedules/{schedule}', [ReportScheduleController::class, 'update'])->name('reports.schedules.update');
    Route::delete('reports/schedules/{schedule}', [ReportScheduleController::class, 'destroy'])->name('reports.schedules.destroy');
});
