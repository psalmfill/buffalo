<?php

use App\Http\Controllers\AccessRequests\AccessRequestController;
use Illuminate\Support\Facades\Route;

// Cross-jurisdiction access (Phase 3C). A request is created only from a
// hit token (POST /access-requests); no route takes a record identifier.
Route::middleware(['auth'])->group(function () {
    Route::get('access-requests', [AccessRequestController::class, 'index'])->name('access-requests.index');
    Route::post('access-requests', [AccessRequestController::class, 'store'])->name('access-requests.store');

    Route::whereUlid('accessRequest')->group(function () {
        Route::get('access-requests/{accessRequest}', [AccessRequestController::class, 'show'])->name('access-requests.show');
        Route::post('access-requests/{accessRequest}/approve', [AccessRequestController::class, 'approve'])->name('access-requests.approve');
        Route::post('access-requests/{accessRequest}/reject', [AccessRequestController::class, 'reject'])->name('access-requests.reject');
        Route::post('access-requests/{accessRequest}/cancel', [AccessRequestController::class, 'cancel'])->name('access-requests.cancel');
    });

    Route::post('access-grants/{grant}/revoke', [AccessRequestController::class, 'revokeGrant'])->whereUlid('grant')->name('access-grants.revoke');

    Route::post('approval-delegations', [AccessRequestController::class, 'storeDelegation'])->name('approval-delegations.store');
    Route::post('approval-delegations/{delegation}/revoke', [AccessRequestController::class, 'revokeDelegation'])->whereUlid('delegation')->name('approval-delegations.revoke');
});
