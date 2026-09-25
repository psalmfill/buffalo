<?php

use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;

// User management. Authorisation: UserPolicy (per action). {user} is a
// ULID resolved through the jurisdiction scope.
Route::middleware(['auth'])->prefix('users')->name('users.')->group(function () {
    Route::get('/', [UserController::class, 'index'])->name('index');
    Route::get('create', [UserController::class, 'create'])->name('create');
    Route::post('/', [UserController::class, 'store'])->name('store');
    Route::get('{user}', [UserController::class, 'show'])->name('show');
    Route::put('{user}', [UserController::class, 'update'])->name('update');
    Route::put('{user}/jurisdiction', [UserController::class, 'updateJurisdiction'])->name('jurisdiction');
    Route::post('{user}/deactivate', [UserController::class, 'deactivate'])->name('deactivate');
    Route::post('{user}/reactivate', [UserController::class, 'reactivate'])->name('reactivate');
    Route::post('{user}/unlock', [UserController::class, 'unlock'])->name('unlock');
    Route::post('{user}/reset-two-factor', [UserController::class, 'resetTwoFactor'])->name('reset-two-factor');
    Route::post('{user}/setup-link', [UserController::class, 'issueSetupLink'])
        ->middleware('throttle:10,1')
        ->name('setup-link');
});
