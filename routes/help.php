<?php

use App\Http\Controllers\HelpController;
use Illuminate\Support\Facades\Route;

// Help and manuals (Phase 9B.4). Authorisation: the viewHelp gate and the
// manual's audience (HelpController).
Route::middleware(['auth'])->prefix('help')->name('help.')->group(function () {
    Route::get('/', [HelpController::class, 'index'])->name('index');
    Route::get('cards/{role}.pdf', [HelpController::class, 'card'])->where('role', '[a-z_]+')->middleware('throttle:10,1')->name('card');
    Route::get('{kind}/{slug}', [HelpController::class, 'show'])->whereIn('kind', ['start', 'roles', 'guides', 'cards'])->where('slug', '[a-z0-9-]+')->name('show');
});
