<?php

use App\Http\Controllers\Auth\AccountSetupController;
use Illuminate\Support\Facades\Route;

// One-time account setup links (guests only, rate limited per IP).
Route::middleware(['guest', 'throttle:account-setup'])->group(function () {
    Route::get('account/setup/{token}', [AccountSetupController::class, 'show'])->name('account-setup.show');
    Route::post('account/setup/{token}', [AccountSetupController::class, 'store'])->name('account-setup.store');
});
