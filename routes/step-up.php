<?php

use App\Http\Controllers\Auth\StepUpController;
use Illuminate\Support\Facades\Route;

// Step-up authentication for sensitive actions (Phase 8B.2).
Route::middleware(['auth'])->group(function () {
    Route::get('step-up', [StepUpController::class, 'show'])->name('step-up.show');
    Route::post('step-up', [StepUpController::class, 'store'])->middleware('throttle:10,1')->name('step-up.store');
});
