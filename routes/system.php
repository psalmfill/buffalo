<?php

use App\Http\Controllers\System\GoLiveController;
use App\Http\Controllers\System\HierarchyController;
use App\Http\Controllers\System\RolloutController;
use App\Http\Controllers\System\SystemHealthController;
use App\Http\Controllers\System\ThemeController;
use Illuminate\Support\Facades\Route;

// System administration (Phase 8C.4).
Route::middleware(['auth'])->group(function () {
    Route::get('system/health', [SystemHealthController::class, 'index'])->name('system.health');

    // Pilot rollout (Phase 9B.3). {state} is a ULID.
    Route::get('system/rollout', [RolloutController::class, 'index'])->name('system.rollout');
    Route::put('system/rollout/{state}', [RolloutController::class, 'update'])->whereUlid('state')->name('system.rollout.update');

    // Go-live check and client decisions (Phase 9B.6).
    Route::get('system/go-live', [GoLiveController::class, 'index'])->name('system.go-live');
    Route::post('system/go-live/decisions', [GoLiveController::class, 'store'])->name('system.go-live.decisions.store');

    // Hierarchy administration (CRUD coverage step): hierarchy.manage, with
    // step-up and a reason for every change. Entries are national reference
    // data, bound by ULID; {adminLga} and friends avoid the jurisdiction-
    // checked {lga} and {district} bindings of the intelligence pages.
    Route::prefix('system/hierarchy')->name('system.hierarchy.')->controller(HierarchyController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::whereUlid(['state', 'adminDistrict', 'adminLga', 'adminWard'])->group(function () {
            Route::get('states/{state}', 'state')->name('state');
            Route::put('states/{state}/capital', 'updateCapital')->name('states.capital');
            Route::post('states/{state}/districts', 'storeDistrict')->name('districts.store');
            Route::post('states/{state}/lgas', 'storeLga')->name('lgas.store');
            Route::put('districts/{adminDistrict}', 'renameDistrict')->name('districts.update');
            Route::put('districts/{adminDistrict}/active', 'districtActive')->name('districts.active');
            Route::get('lgas/{adminLga}', 'lga')->name('lga');
            Route::put('lgas/{adminLga}', 'renameLga')->name('lgas.update');
            Route::put('lgas/{adminLga}/active', 'lgaActive')->name('lgas.active');
            Route::post('lgas/{adminLga}/wards', 'storeWard')->name('wards.store');
            Route::put('wards/{adminWard}', 'renameWard')->name('wards.update');
            Route::put('wards/{adminWard}/active', 'wardActive')->name('wards.active');
        });
    });

    // Theme colours (Phase 11A.3): theme.manage.
    Route::get('system/theme', [ThemeController::class, 'edit'])->name('system.theme.edit');
    Route::put('system/theme', [ThemeController::class, 'update'])->name('system.theme.update');
    Route::delete('system/theme', [ThemeController::class, 'destroy'])->name('system.theme.destroy');
});
