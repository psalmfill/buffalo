<?php

use App\Http\Controllers\Dashboard\LensController;
use App\Http\Controllers\Dashboard\PlaceDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Security\CspReportController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// No public landing page: guests go to the login form, users to the dashboard.
Route::get('/', fn (Request $request): RedirectResponse => $request->user() !== null
    ? redirect()->route('dashboard')
    : redirect()->route('login'))->name('home');

// CSP violation reports from browsers (Phase 8B.1): public, throttled, no CSRF.
Route::post('csp-report', CspReportController::class)->middleware('throttle:60,1')->name('csp.report');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    // National users: one state's or zone's dashboard, and the state lens (Phase 11A.2).
    Route::get('dashboard/states/{state}', [PlaceDashboardController::class, 'state'])->name('dashboard.states.show');
    Route::get('dashboard/zones/{zone}', [PlaceDashboardController::class, 'zone'])->name('dashboard.zones.show');
    Route::put('lens', [LensController::class, 'update'])->name('lens.update');
    Route::delete('lens', [LensController::class, 'destroy'])->name('lens.destroy');

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
require __DIR__.'/system.php';
require __DIR__.'/reference-data.php';
require __DIR__.'/local-government.php';
require __DIR__.'/community-information.php';
require __DIR__.'/person-merges.php';
require __DIR__.'/persons.php';
require __DIR__.'/political.php';
require __DIR__.'/persons-of-interest.php';
require __DIR__.'/access-requests.php';
require __DIR__.'/security.php';
require __DIR__.'/organizations.php';
require __DIR__.'/pickers.php';
require __DIR__.'/incidents.php';
require __DIR__.'/evidence.php';
require __DIR__.'/flashpoints.php';
require __DIR__.'/threats.php';
require __DIR__.'/map.php';
require __DIR__.'/disseminations.php';
require __DIR__.'/search.php';
require __DIR__.'/analytics.php';
require __DIR__.'/reports.php';
require __DIR__.'/step-up.php';
require __DIR__.'/help.php';

// UAT-only routes (Phase 9B.5): absent from production deployments
// (registered locally for the route helpers; they answer 404 outside UAT).
if (app()->environment(['uat', 'testing', 'local'])) {
    require __DIR__.'/uat.php';
}
