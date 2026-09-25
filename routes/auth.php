<?php

use App\Http\Controllers\Auth\AccountSetupController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// One-time account setup links (guests only, rate limited per IP).
Route::middleware(['guest', 'throttle:account-setup'])->group(function () {
    Route::get('account/setup/{token}', [AccountSetupController::class, 'show'])->name('account-setup.show');
    Route::post('account/setup/{token}', [AccountSetupController::class, 'store'])->name('account-setup.store');
});

// Mandatory two-factor enrolment (EnsureTwoFactorEnrolled sends every
// signed-in user without confirmed 2FA here).
Route::middleware(['auth'])->group(function () {
    Route::get('two-factor/setup', [TwoFactorSetupController::class, 'show'])
        ->middleware(RequirePassword::class)
        ->name('two-factor.setup');

    Route::post('two-factor/setup/complete', [TwoFactorSetupController::class, 'complete'])
        ->name('two-factor.setup.complete');
});
