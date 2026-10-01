<?php

use App\Http\Controllers\Disseminations\DisseminationController;
use Illuminate\Support\Facades\Route;

// Dissemination of threat reports and flashpoints (Phase 7A). Records are
// bound through the viewer's scopes; every action is authorised by
// DisseminationPolicy.
Route::middleware(['auth'])->group(function () {
    Route::get('disseminations', [DisseminationController::class, 'index'])->name('disseminations.index');

    Route::whereUlid(['threat', 'flashpoint', 'dissemination', 'disseminationRequest'])->group(function () {
        Route::post('threats/{threat}/disseminations', [DisseminationController::class, 'storeThreat'])->name('threats.disseminations.store');
        Route::post('flashpoints/{flashpoint}/disseminations', [DisseminationController::class, 'storeFlashpoint'])->name('flashpoints.disseminations.store');
        Route::post('threats/{threat}/dissemination-requests', [DisseminationController::class, 'requestThreat'])->name('threats.dissemination-requests.store');
        Route::post('flashpoints/{flashpoint}/dissemination-requests', [DisseminationController::class, 'requestFlashpoint'])->name('flashpoints.dissemination-requests.store');
        Route::post('disseminations/{dissemination}/onward', [DisseminationController::class, 'onward'])->name('disseminations.onward');
        Route::post('disseminations/{dissemination}/acknowledge', [DisseminationController::class, 'acknowledge'])->name('disseminations.acknowledge');
        Route::post('disseminations/{dissemination}/renew', [DisseminationController::class, 'renew'])->name('disseminations.renew');
        Route::post('disseminations/{dissemination}/revoke', [DisseminationController::class, 'revoke'])->name('disseminations.revoke');
        Route::post('dissemination-requests/{disseminationRequest}/decline', [DisseminationController::class, 'decline'])->name('dissemination-requests.decline');
    });
});
