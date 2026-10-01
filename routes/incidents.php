<?php

use App\Http\Controllers\Incidents\IncidentController;
use App\Http\Controllers\Incidents\IncidentPersonController;
use App\Http\Controllers\Incidents\IncidentWorkflowController;
use Illuminate\Support\Facades\Route;

// Incidents (Phase 5A: drafts). {incident} is bound through its scopes
// (jurisdiction and classification): anything else is 404.
Route::middleware(['auth'])->group(function () {
    Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('incidents/create', [IncidentController::class, 'create'])->name('incidents.create');
    Route::post('incidents', [IncidentController::class, 'store'])->name('incidents.store');
    Route::post('incidents/person-check', [IncidentPersonController::class, 'check'])->name('incidents.person-check');
    Route::post('incidents/new-person', [IncidentPersonController::class, 'store'])->name('incidents.new-person');

    Route::whereUlid('incident')->group(function () {
        Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
        Route::get('incidents/{incident}/edit', [IncidentController::class, 'edit'])->name('incidents.edit');
        Route::put('incidents/{incident}', [IncidentController::class, 'update'])->name('incidents.update');

        // Workflow (Phase 5C).
        Route::get('incidents/{incident}/duplicates', [IncidentWorkflowController::class, 'duplicates'])->name('incidents.duplicates');
        foreach (['submit', 'return', 'verify', 'resolve', 'reopen', 'close', 'invalidate', 'escalate', 'acknowledge'] as $step) {
            Route::post("incidents/{incident}/{$step}", [IncidentWorkflowController::class, $step])->name("incidents.{$step}");
        }
        Route::post('incidents/{incident}/start-investigation', [IncidentWorkflowController::class, 'startInvestigation'])->name('incidents.start-investigation');
        Route::post('incidents/{incident}/updates', [IncidentWorkflowController::class, 'storeUpdate'])->name('incidents.updates.store');
    });
});
