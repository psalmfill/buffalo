<?php

use App\Http\Controllers\Map\BlackspotSuggestionController;
use App\Http\Controllers\Map\MapController;
use App\Http\Controllers\Map\MapLayerController;
use Illuminate\Support\Facades\Route;

// Operational map and blackspot suggestions (Phase 6C).
Route::middleware(['auth'])->group(function () {
    Route::get('map', [MapController::class, 'show'])->name('map.show');

    Route::prefix('map/layers')->name('map.layers.')->group(function () {
        Route::get('density', [MapLayerController::class, 'density'])->name('density');
        Route::get('lga-choropleth', [MapLayerController::class, 'lgaChoropleth'])->name('lga-choropleth');
        Route::get('state-choropleth', [MapLayerController::class, 'stateChoropleth'])->name('state-choropleth');
        Route::get('flashpoints', [MapLayerController::class, 'flashpoints'])->name('flashpoints');
        Route::get('threats', [MapLayerController::class, 'threats'])->name('threats');
        Route::get('formations', [MapLayerController::class, 'formations'])->name('formations');
        Route::get('sites', [MapLayerController::class, 'sites'])->name('sites');
        Route::get('communities', [MapLayerController::class, 'communities'])->name('communities');
        Route::get('suggestions', [MapLayerController::class, 'suggestions'])->name('suggestions');
    });

    Route::get('blackspot-suggestions', [BlackspotSuggestionController::class, 'index'])->name('blackspot-suggestions.index');
    Route::post('blackspot-suggestions/refresh', [BlackspotSuggestionController::class, 'refresh'])->name('blackspot-suggestions.refresh');
    Route::post('blackspot-suggestions/{suggestion}/dismiss', [BlackspotSuggestionController::class, 'dismiss'])->whereUlid('suggestion')->name('blackspot-suggestions.dismiss');
});
