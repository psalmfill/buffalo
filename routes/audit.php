<?php

use App\Http\Controllers\Audit\AuditLogController;
use App\Http\Controllers\Audit\ExportReviewController;
use App\Http\Controllers\Audit\LoginHistoryController;
use Illuminate\Support\Facades\Route;

// Read-only audit viewers. There are no routes to change or delete entries.
Route::middleware(['auth'])->group(function () {
    Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('login-history', [LoginHistoryController::class, 'index'])->name('login-history.index');
    Route::get('audit/exports', [ExportReviewController::class, 'index'])->name('audit.exports-review');
});
