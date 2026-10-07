<?php

use App\Http\Controllers\Uat\FeedbackController;
use App\Http\Middleware\EnsureUatEnvironment;
use Illuminate\Support\Facades\Route;

// UAT feedback (Phase 9B.5). Registered only in the UAT environment (and in
// tests, which prove the 404 elsewhere); never in a production deployment.
Route::middleware(['auth', EnsureUatEnvironment::class])->prefix('uat')->name('uat.')->group(function () {
    Route::post('feedback', [FeedbackController::class, 'store'])->middleware('throttle:30,1')->name('feedback.store');
    Route::get('feedback', [FeedbackController::class, 'index'])->name('feedback.index');
    Route::get('feedback/export', [FeedbackController::class, 'export'])->name('feedback.export');
});
