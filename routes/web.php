<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// No public landing page: guests go to the login form, users to the dashboard.
Route::get('/', fn (Request $request): RedirectResponse => $request->user() !== null
    ? redirect()->route('dashboard')
    : redirect()->route('login'))->name('home');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/auth.php';
require __DIR__.'/settings.php';
