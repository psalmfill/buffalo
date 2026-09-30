<?php

use App\Http\Controllers\Pickers\PickerController;
use Illuminate\Support\Facades\Route;

// Searchable pickers (Phase 5A). POSTed JSON; every result goes through
// the entity's scopes and policy.
Route::middleware(['auth'])->prefix('pickers')->name('pickers.')->group(function () {
    Route::post('persons', [PickerController::class, 'persons'])->name('persons');
    Route::post('communities', [PickerController::class, 'communities'])->name('communities');
    Route::post('formations', [PickerController::class, 'formations'])->name('formations');
    Route::post('sites', [PickerController::class, 'sites'])->name('sites');
    Route::post('groups', [PickerController::class, 'groups'])->name('groups');
    Route::post('organizations', [PickerController::class, 'organizations'])->name('organizations');
    Route::post('users', [PickerController::class, 'users'])->name('users');
});
