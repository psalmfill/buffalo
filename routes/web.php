<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// No public landing page: guests go to the login form, users to the dashboard.
Route::get('/', fn (Request $request): RedirectResponse => $request->user() !== null
    ? redirect()->route('dashboard')
    : redirect()->route('login'))->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // The signed-in user's own notifications.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->whereUuid('notification')
        ->name('notifications.read');
});

require __DIR__.'/auth.php';
require __DIR__.'/settings.php';
require __DIR__.'/users.php';
require __DIR__.'/audit.php';
