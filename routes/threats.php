<?php

use App\Http\Controllers\Threats\ThreatLifecycleController;
use App\Http\Controllers\Threats\ThreatLinkController;
use App\Http\Controllers\Threats\ThreatReportController;
use Illuminate\Support\Facades\Route;

// Threat reports (Phase 6B). {threat} is bound through its scopes
// (jurisdiction and classification): anything else is 404.
Route::middleware(['auth'])->group(function () {
    Route::get('threats', [ThreatReportController::class, 'index'])->name('threats.index')->middleware('lens');
    Route::get('threats/create', [ThreatReportController::class, 'create'])->name('threats.create');
    Route::post('threats', [ThreatReportController::class, 'store'])->name('threats.store');

    Route::whereUlid(['threat', 'link'])->group(function () {
        Route::get('threats/{threat}', [ThreatReportController::class, 'show'])->name('threats.show');
        Route::get('threats/{threat}/edit', [ThreatReportController::class, 'edit'])->name('threats.edit');
        Route::put('threats/{threat}', [ThreatReportController::class, 'update'])->name('threats.update');
        Route::delete('threats/{threat}', [ThreatReportController::class, 'destroy'])->name('threats.destroy');
        Route::post('threats/{threat}/status', [ThreatLifecycleController::class, 'status'])->name('threats.status');
        Route::post('threats/{threat}/extend', [ThreatLifecycleController::class, 'extend'])->name('threats.extend');
        Route::post('threats/{threat}/reactivate', [ThreatLifecycleController::class, 'reactivate'])->name('threats.reactivate');
        Route::post('threats/{threat}/links/{kind}', [ThreatLinkController::class, 'store'])->whereIn('kind', ['flashpoints', 'groups', 'incidents'])->name('threats.links.store');
        Route::delete('threats/{threat}/links/{kind}/{link}', [ThreatLinkController::class, 'destroy'])->whereIn('kind', ['flashpoints', 'groups', 'incidents'])->name('threats.links.destroy');
    });
});
