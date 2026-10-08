<?php

use App\Http\Controllers\Persons\PersonDuplicateController;
use App\Http\Controllers\Persons\PersonMergeController;
use App\Http\Controllers\Persons\PersonMergeRequestController;
use Illuminate\Support\Facades\Route;

// Merging duplicate persons (Phase 10A). Persons are named by ULID in the
// query string or the body (never names, NINs or phone numbers); {merge}
// and {mergeRequest} are bound through the jurisdiction scope.
Route::middleware(['auth'])->group(function () {
    Route::get('persons/duplicates', [PersonDuplicateController::class, 'index'])->name('persons.duplicates.index');
    Route::post('persons/duplicates/dismiss', [PersonDuplicateController::class, 'dismiss'])->name('persons.duplicates.dismiss');

    Route::get('persons/merge', [PersonMergeController::class, 'review'])->name('persons.merge.review');
    Route::post('persons/merge', [PersonMergeController::class, 'store'])->name('persons.merge.store');
    Route::post('persons/merges/{merge}/undo', [PersonMergeController::class, 'undo'])->whereUlid('merge')->name('persons.merges.undo');
    Route::get('persons/merges/{merge}/history', [PersonMergeController::class, 'history'])->whereUlid('merge')->name('persons.merges.history');

    Route::get('persons/merge-requests', [PersonMergeRequestController::class, 'index'])->name('persons.merge-requests.index');
    Route::post('persons/merge-requests', [PersonMergeRequestController::class, 'store'])->name('persons.merge-requests.store');
    Route::post('persons/merge-requests/{mergeRequest}/decline', [PersonMergeRequestController::class, 'decline'])->whereUlid('mergeRequest')->name('persons.merge-requests.decline');
});
