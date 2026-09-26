<?php

use App\Http\Controllers\Persons\PersonAliasController;
use App\Http\Controllers\Persons\PersonAssociationController;
use App\Http\Controllers\Persons\PersonController;
use App\Http\Controllers\Persons\PersonPhotoController;
use App\Http\Controllers\Persons\PersonPickerController;
use Illuminate\Support\Facades\Route;

// Persons (Phase 3A). {person} is bound through the jurisdiction and
// classification scopes: anything else is 404. Searches, the duplicate
// check and the picker are POSTed so names, NINs and phone numbers never
// appear in URLs.
Route::middleware(['auth'])->group(function () {
    Route::get('persons', [PersonController::class, 'index'])->name('persons.index');
    Route::post('persons/search', [PersonController::class, 'search'])->name('persons.search');
    // A reload of the search results page: start again.
    Route::get('persons/search', fn () => redirect()->route('persons.index'));
    Route::post('persons/similar', [PersonController::class, 'similar'])->name('persons.similar');
    Route::post('persons/picker', PersonPickerController::class)->name('persons.picker');
    Route::get('persons/create', [PersonController::class, 'create'])->name('persons.create');
    Route::post('persons', [PersonController::class, 'store'])->name('persons.store');

    Route::whereUlid('person')->group(function () {
        Route::get('persons/{person}', [PersonController::class, 'show'])->name('persons.show');
        Route::get('persons/{person}/edit', [PersonController::class, 'edit'])->name('persons.edit');
        Route::put('persons/{person}', [PersonController::class, 'update'])->name('persons.update');
        Route::delete('persons/{person}', [PersonController::class, 'destroy'])->name('persons.destroy');

        Route::post('persons/{person}/photos', [PersonPhotoController::class, 'store'])->name('persons.photos.store');
        Route::get('persons/{person}/photos/{media}/{conversion?}', [PersonPhotoController::class, 'show'])
            ->whereUlid('media')
            ->whereIn('conversion', PersonPhotoController::CONVERSIONS)
            ->name('persons.photos.show');

        Route::post('persons/{person}/aliases', [PersonAliasController::class, 'store'])->name('persons.aliases.store');
        Route::delete('persons/{person}/aliases/{alias}', [PersonAliasController::class, 'destroy'])
            ->whereUlid('alias')
            ->name('persons.aliases.destroy');

        Route::post('persons/{person}/associations', [PersonAssociationController::class, 'store'])->name('persons.associations.store');
        Route::delete('persons/{person}/associations/{association}', [PersonAssociationController::class, 'destroy'])
            ->whereUlid('association')
            ->name('persons.associations.destroy');
    });
});
