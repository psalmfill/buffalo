<?php

use App\Http\Controllers\Flashpoints\FlashpointAssessmentController;
use App\Http\Controllers\Flashpoints\FlashpointController;
use App\Http\Controllers\Flashpoints\FlashpointLinkController;
use Illuminate\Support\Facades\Route;

// Flashpoints and blackspots (Phase 6A). {flashpoint} and {link} are bound
// through their scopes (jurisdiction and classification): anything else is 404.
Route::middleware(['auth'])->group(function () {
    Route::get('flashpoints', [FlashpointController::class, 'index'])->name('flashpoints.index');
    Route::get('flashpoints/create', [FlashpointController::class, 'create'])->name('flashpoints.create');
    Route::post('flashpoints', [FlashpointController::class, 'store'])->name('flashpoints.store');

    Route::whereUlid(['flashpoint', 'link'])->group(function () {
        Route::get('flashpoints/{flashpoint}', [FlashpointController::class, 'show'])->name('flashpoints.show');
        Route::get('flashpoints/{flashpoint}/edit', [FlashpointController::class, 'edit'])->name('flashpoints.edit');
        Route::put('flashpoints/{flashpoint}', [FlashpointController::class, 'update'])->name('flashpoints.update');
        Route::delete('flashpoints/{flashpoint}', [FlashpointController::class, 'destroy'])->name('flashpoints.destroy');
        Route::post('flashpoints/{flashpoint}/assessments', [FlashpointAssessmentController::class, 'store'])->name('flashpoints.assessments.store');
        Route::post('flashpoints/{flashpoint}/incidents', [FlashpointLinkController::class, 'storeIncident'])->name('flashpoints.incidents.store');
        Route::delete('flashpoints/{flashpoint}/incidents/{link}', [FlashpointLinkController::class, 'destroyIncident'])->name('flashpoints.incidents.destroy');
        Route::post('flashpoints/{flashpoint}/security-notes', [FlashpointLinkController::class, 'storeSecurityNote'])->name('flashpoints.security-notes.store');
        Route::delete('flashpoints/{flashpoint}/security-notes/{link}', [FlashpointLinkController::class, 'destroySecurityNote'])->name('flashpoints.security-notes.destroy');
    });
});
