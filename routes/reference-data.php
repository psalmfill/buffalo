<?php

use App\Http\Controllers\ReferenceData\IncidentTypeSchemaController;
use App\Http\Controllers\ReferenceData\ReferenceDataController;
use Illuminate\Support\Facades\Route;

// Reference data (Phase 2A). {table} is a ReferenceTable slug (unknown
// slugs are 404). Values are never deleted, so there is no delete route.
Route::middleware(['auth'])->prefix('reference-data')->name('reference-data.')->group(function () {
    Route::get('/', [ReferenceDataController::class, 'home'])->name('home');

    Route::put('incident-types/{incidentType}/schema', [IncidentTypeSchemaController::class, 'update'])
        ->name('incident-types.schema');

    Route::get('{table}', [ReferenceDataController::class, 'index'])->name('index');
    Route::get('{table}/create', [ReferenceDataController::class, 'create'])->name('create');
    Route::post('{table}', [ReferenceDataController::class, 'store'])->name('store');

    Route::whereUlid('value')->group(function () {
        Route::get('{table}/{value}', [ReferenceDataController::class, 'show'])->name('show');
        Route::get('{table}/{value}/edit', [ReferenceDataController::class, 'edit'])->name('edit');
        Route::put('{table}/{value}', [ReferenceDataController::class, 'update'])->name('update');
        Route::post('{table}/{value}/activate', [ReferenceDataController::class, 'activate'])->name('activate');
        Route::post('{table}/{value}/deactivate', [ReferenceDataController::class, 'deactivate'])->name('deactivate');
    });
});
