<?php

use App\Http\Controllers\Evidence\EvidenceFileController;
use App\Http\Controllers\Evidence\IncidentEvidenceController;
use Illuminate\Support\Facades\Route;

// Incident evidence (Phase 5B). {incident} and {evidence} are bound through
// their scopes (jurisdiction and classification): anything else is 404.
Route::middleware(['auth'])->group(function () {
    Route::post('incidents/{incident}/evidence', [IncidentEvidenceController::class, 'store'])
        ->whereUlid('incident')->name('evidence.store');

    Route::whereUlid('evidence')->group(function () {
        Route::get('evidence/{evidence}', [IncidentEvidenceController::class, 'show'])->name('evidence.show');
        Route::put('evidence/{evidence}', [IncidentEvidenceController::class, 'update'])->name('evidence.update');
        Route::delete('evidence/{evidence}', [IncidentEvidenceController::class, 'destroy'])->name('evidence.destroy');
        Route::post('evidence/{evidence}/verify', [IncidentEvidenceController::class, 'verify'])->name('evidence.verify');
        Route::get('evidence/{evidence}/custody', [IncidentEvidenceController::class, 'custody'])->name('evidence.custody');

        Route::get('evidence/{evidence}/thumb', [EvidenceFileController::class, 'thumb'])->name('evidence.thumb');
        Route::get('evidence/{evidence}/preview', [EvidenceFileController::class, 'preview'])->name('evidence.preview');
        Route::get('evidence/{evidence}/stream', [EvidenceFileController::class, 'stream'])->name('evidence.stream');
        Route::get('evidence/{evidence}/download', [EvidenceFileController::class, 'download'])->name('evidence.download');
    });
});
