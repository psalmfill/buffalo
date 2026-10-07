<?php

use App\Http\Controllers\Users\OnboardingController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\Users\UserImportController;
use Illuminate\Support\Facades\Route;

// User management. Authorisation: UserPolicy (per action). {user} is a
// ULID resolved through the jurisdiction scope.
Route::middleware(['auth'])->prefix('users')->name('users.')->group(function () {
    Route::get('/', [UserController::class, 'index'])->name('index');
    Route::get('create', [UserController::class, 'create'])->name('create');
    Route::post('/', [UserController::class, 'store'])->name('store');

    // Bulk import and onboarding (Phase 9B.2), super admins. Before the
    // {user} routes.
    Route::get('import', [UserImportController::class, 'create'])->name('import.create');
    Route::get('import/template', [UserImportController::class, 'template'])->name('import.template');
    Route::post('import/preview', [UserImportController::class, 'preview'])->name('import.preview');
    Route::get('import/preview', [UserImportController::class, 'restart'])->name('import.restart');
    Route::post('import', [UserImportController::class, 'store'])->name('import.store');
    Route::get('onboarding', [OnboardingController::class, 'index'])->name('onboarding');
    Route::post('onboarding/email', [OnboardingController::class, 'email'])->middleware('throttle:10,1')->name('onboarding.email');
    Route::post('onboarding/handover', [OnboardingController::class, 'startHandover'])->name('onboarding.handover.start');
    Route::get('onboarding/handover', [OnboardingController::class, 'handover'])->name('onboarding.handover');
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
