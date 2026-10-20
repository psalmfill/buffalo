<?php

use App\Http\Controllers\Arrests\ArrestController;
use App\Http\Controllers\Arrests\ArrestPersonController;
use App\Http\Controllers\Arrests\ArrestStatusController;
use Illuminate\Support\Facades\Route;

// Arrests (Phase 11C). {arrest} is bound through its scopes (jurisdiction
// and classification): anything else is 404.
Route::middleware(['auth'])->group(function () {
    Route::get('arrests', [ArrestController::class, 'index'])->name('arrests.index')->middleware('lens');
    Route::get('arrests/create', [ArrestController::class, 'create'])->name('arrests.create');
    Route::post('arrests', [ArrestController::class, 'store'])->name('arrests.store');

    // People entered from the arrest form (match context arrest_entry).
    Route::post('arrests/persons/check', [ArrestPersonController::class, 'check'])->name('arrests.persons.check');
    Route::post('arrests/persons', [ArrestPersonController::class, 'store'])->name('arrests.persons.store');
    Route::post('arrests/persons/suggestions', [ArrestPersonController::class, 'suggestions'])->name('arrests.persons.suggestions');

    Route::whereUlid('arrest')->group(function () {
        Route::get('arrests/{arrest}', [ArrestController::class, 'show'])->name('arrests.show');
        Route::get('arrests/{arrest}/edit', [ArrestController::class, 'edit'])->name('arrests.edit');
        Route::put('arrests/{arrest}', [ArrestController::class, 'update'])->name('arrests.update');
        Route::delete('arrests/{arrest}', [ArrestController::class, 'destroy'])->name('arrests.destroy');
        Route::post('arrests/{arrest}/status', [ArrestStatusController::class, 'store'])->name('arrests.status');
    });
});
