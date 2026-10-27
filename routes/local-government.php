<?php

use App\Http\Controllers\LocalGovernment\CommunityController;
use App\Http\Controllers\LocalGovernment\CommunityImportController;
use App\Http\Controllers\LocalGovernment\LgaController;
use App\Http\Controllers\LocalGovernment\StateController;
use App\Models\Lga;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// {lga}: LGAs are reference data (not scoped), so LGA pages bind them
// within the signed-in user's jurisdiction. Anything else is 404, exactly
// like jurisdiction-scoped models ({community}).
Route::bind('lga', function (string $value): Lga {
    $user = Auth::user();
    abort_unless($user instanceof User, 404);

    return app(JurisdictionOptions::class)->lgaQuery($user)->where('lgas.ulid', $value)->firstOrFail();
});

Route::middleware(['auth'])->group(function () {
    // State pages (Phase 11F). {state} is bound by ULID (states are
    // reference data); StatePolicy::view answers 404 outside reach.
    Route::get('states', [StateController::class, 'index'])->name('states.index')->middleware('lens');

    Route::whereUlid('state')->group(function () {
        Route::get('states/{state}', [StateController::class, 'show'])->name('states.show');
        Route::put('states/{state}/profile', [StateController::class, 'updateProfile'])->name('states.profile.update');
        Route::post('states/{state}/review', [StateController::class, 'markReviewed'])->name('states.review');
    });

    Route::get('lgas', [LgaController::class, 'index'])->name('lgas.index');

    Route::whereUlid('lga')->group(function () {
        Route::get('lgas/{lga}', [LgaController::class, 'show'])->name('lgas.show');
        Route::put('lgas/{lga}/profile', [LgaController::class, 'updateProfile'])->name('lgas.profile.update');
        Route::post('lgas/{lga}/review', [LgaController::class, 'markReviewed'])->name('lgas.review');
        Route::get('lgas/{lga}/communities/create', [CommunityController::class, 'create'])->name('lgas.communities.create');
        Route::post('lgas/{lga}/communities', [CommunityController::class, 'store'])->name('lgas.communities.store');
    });

    Route::get('communities', [CommunityController::class, 'index'])->name('communities.index');

    Route::get('communities/import', [CommunityImportController::class, 'create'])->name('communities.import.create');
    Route::get('communities/import/template', [CommunityImportController::class, 'template'])->name('communities.import.template');
    Route::post('communities/import/preview', [CommunityImportController::class, 'preview'])->name('communities.import.preview');
    Route::get('communities/import/preview', [CommunityImportController::class, 'restart'])->name('communities.import.restart');
    Route::post('communities/import', [CommunityImportController::class, 'store'])->name('communities.import.store');

    Route::whereUlid('community')->group(function () {
        Route::get('communities/{community}', [CommunityController::class, 'show'])->name('communities.show');
        Route::get('communities/{community}/edit', [CommunityController::class, 'edit'])->name('communities.edit');
        Route::put('communities/{community}', [CommunityController::class, 'update'])->name('communities.update');
        Route::delete('communities/{community}', [CommunityController::class, 'destroy'])->name('communities.destroy');
    });
});
