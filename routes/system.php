<?php

use App\Http\Controllers\System\SystemHealthController;
use Illuminate\Support\Facades\Route;

// System administration (Phase 8C.4).
Route::middleware(['auth'])->group(function () {
    Route::get('system/health', [SystemHealthController::class, 'index'])->name('system.health');
});
