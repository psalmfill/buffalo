<?php

use App\Http\Controllers\System\GoLiveController;
use App\Http\Controllers\System\RolloutController;
use App\Http\Controllers\System\SystemHealthController;
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
});
