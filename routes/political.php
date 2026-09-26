<?php

use App\Http\Controllers\Political\PoliticalOfficeController;
use App\Http\Controllers\Political\SenatorialDistrictController;
use App\Models\SenatorialDistrict;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// {district}: senatorial districts are reference data (not scoped), bound
// within the signed-in user's jurisdiction like {lga}. Anything else is 404.
Route::bind('district', function (string $value): SenatorialDistrict {
    $user = Auth::user();
    abort_unless($user instanceof User, 404);

    return app(JurisdictionOptions::class)->districtQuery($user)->where('senatorial_districts.ulid', $value)->firstOrFail();
});

// Political information (Phase 3B). {person} and {office} are bound
// through their scopes.
Route::middleware(['auth'])->group(function () {
    Route::get('senatorial-districts', [SenatorialDistrictController::class, 'index'])->name('senatorial-districts.index');
    Route::get('senatorial-districts/{district}', [SenatorialDistrictController::class, 'show'])->whereUlid('district')->name('senatorial-districts.show');

    Route::whereUlid('person')->group(function () {
        Route::get('persons/{person}/political-offices/create', [PoliticalOfficeController::class, 'create'])->name('persons.political-offices.create');
        Route::post('persons/{person}/political-offices', [PoliticalOfficeController::class, 'store'])->name('persons.political-offices.store');
    });

    Route::whereUlid('office')->group(function () {
        Route::get('political-offices/{office}/edit', [PoliticalOfficeController::class, 'edit'])->name('political-offices.edit');
        Route::put('political-offices/{office}', [PoliticalOfficeController::class, 'update'])->name('political-offices.update');
        Route::delete('political-offices/{office}', [PoliticalOfficeController::class, 'destroy'])->name('political-offices.destroy');
    });
});
