<?php

use App\Http\Controllers\Search\SearchController;
use Illuminate\Support\Facades\Route;

// Global search (Phase 7B): 60 searches per user per minute.
Route::middleware(['auth', 'throttle:global-search'])->group(function () {
    Route::get('search', [SearchController::class, 'index'])->name('search.index');
    Route::get('search/quick', [SearchController::class, 'quick'])->name('search.quick');
});
