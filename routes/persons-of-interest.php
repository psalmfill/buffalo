<?php

use App\Http\Controllers\CriminalGroups\CriminalGroupController;
use App\Http\Controllers\CriminalGroups\GroupAreaController;
use App\Http\Controllers\CriminalGroups\GroupMemberController;
use App\Http\Controllers\PersonsOfInterest\PersonOfInterestController;
use Illuminate\Support\Facades\Route;

// Persons of interest and criminal groups (Phase 3B). {person}, {poi} and
// {group} are bound through their scopes (jurisdiction, and the person's
// or group's classification): anything else is 404.
Route::middleware(['auth'])->group(function () {
    Route::get('persons-of-interest', [PersonOfInterestController::class, 'index'])->name('persons-of-interest.index');

    Route::whereUlid('person')->group(function () {
        Route::get('persons/{person}/person-of-interest/create', [PersonOfInterestController::class, 'create'])->name('persons.person-of-interest.create');
        Route::post('persons/{person}/person-of-interest', [PersonOfInterestController::class, 'store'])->name('persons.person-of-interest.store');
    });

    Route::whereUlid('poi')->group(function () {
        Route::get('persons-of-interest/{poi}/edit', [PersonOfInterestController::class, 'edit'])->name('persons-of-interest.edit');
        Route::put('persons-of-interest/{poi}', [PersonOfInterestController::class, 'update'])->name('persons-of-interest.update');
        Route::post('persons-of-interest/{poi}/status', [PersonOfInterestController::class, 'status'])->name('persons-of-interest.status');
        Route::post('persons-of-interest/{poi}/review', [PersonOfInterestController::class, 'review'])->name('persons-of-interest.review');
        Route::delete('persons-of-interest/{poi}', [PersonOfInterestController::class, 'destroy'])->name('persons-of-interest.destroy');
    });

    Route::get('criminal-groups', [CriminalGroupController::class, 'index'])->name('criminal-groups.index');
    Route::get('criminal-groups/create', [CriminalGroupController::class, 'create'])->name('criminal-groups.create');
    Route::post('criminal-groups', [CriminalGroupController::class, 'store'])->name('criminal-groups.store');

    Route::whereUlid('group')->group(function () {
        Route::get('criminal-groups/{group}', [CriminalGroupController::class, 'show'])->name('criminal-groups.show');
        Route::get('criminal-groups/{group}/edit', [CriminalGroupController::class, 'edit'])->name('criminal-groups.edit');
        Route::put('criminal-groups/{group}', [CriminalGroupController::class, 'update'])->name('criminal-groups.update');
        Route::delete('criminal-groups/{group}', [CriminalGroupController::class, 'destroy'])->name('criminal-groups.destroy');

        Route::get('criminal-groups/{group}/area-lgas/{state}', [GroupAreaController::class, 'lgas'])->whereUlid('state')->name('criminal-groups.area-lgas');
        Route::post('criminal-groups/{group}/areas', [GroupAreaController::class, 'store'])->name('criminal-groups.areas.store');
        Route::delete('criminal-groups/{group}/areas/{area}', [GroupAreaController::class, 'destroy'])->whereUlid('area')->name('criminal-groups.areas.destroy');

        Route::post('criminal-groups/{group}/members', [GroupMemberController::class, 'store'])->name('criminal-groups.members.store');
        Route::put('criminal-groups/{group}/members/{member}', [GroupMemberController::class, 'update'])->whereUlid('member')->name('criminal-groups.members.update');
        Route::delete('criminal-groups/{group}/members/{member}', [GroupMemberController::class, 'destroy'])->whereUlid('member')->name('criminal-groups.members.destroy');
    });
});
