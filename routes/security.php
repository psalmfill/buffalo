<?php

use App\Http\Controllers\Security\AgencyFormationController;
use App\Http\Controllers\Security\CoverageReportController;
use Illuminate\Support\Facades\Route;

// Security agencies (Phase 4A): formations and coverage reports.
// {formation} and {report} are bound through their scopes (jurisdiction and
// classification): anything else is 404.
Route::middleware(['auth'])->group(function () {
    Route::get('formations', [AgencyFormationController::class, 'index'])->name('formations.index')->middleware('lens');
    Route::get('formations/create', [AgencyFormationController::class, 'create'])->name('formations.create');
    Route::post('formations', [AgencyFormationController::class, 'store'])->name('formations.store');

    Route::whereUlid('formation')->group(function () {
        Route::get('formations/{formation}', [AgencyFormationController::class, 'show'])->name('formations.show');
        Route::get('formations/{formation}/edit', [AgencyFormationController::class, 'edit'])->name('formations.edit');
        Route::put('formations/{formation}', [AgencyFormationController::class, 'update'])->name('formations.update');
        Route::delete('formations/{formation}', [AgencyFormationController::class, 'destroy'])->name('formations.destroy');
    });

    Route::get('coverage-reports', [CoverageReportController::class, 'index'])->name('coverage-reports.index')->middleware('lens');
    Route::get('coverage-reports/create', [CoverageReportController::class, 'create'])->name('coverage-reports.create');
    Route::post('coverage-reports', [CoverageReportController::class, 'store'])->name('coverage-reports.store');

    Route::whereUlid('report')->group(function () {
        Route::get('coverage-reports/{report}', [CoverageReportController::class, 'show'])->name('coverage-reports.show');
        Route::get('coverage-reports/{report}/edit', [CoverageReportController::class, 'edit'])->name('coverage-reports.edit');
        Route::put('coverage-reports/{report}', [CoverageReportController::class, 'update'])->name('coverage-reports.update');
        Route::post('coverage-reports/{report}/return', [CoverageReportController::class, 'returnReport'])->name('coverage-reports.return');
        Route::post('coverage-reports/{report}/review', [CoverageReportController::class, 'review'])->name('coverage-reports.review');
        Route::delete('coverage-reports/{report}', [CoverageReportController::class, 'destroy'])->name('coverage-reports.destroy');
    });
});
