<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\BuildLgaDashboard;
use App\Actions\Dashboard\BuildNationalDashboard;
use App\Actions\Dashboard\BuildStateDashboard;
use App\Actions\Dashboard\BuildSystemDashboard;
use App\Enums\Role;
use App\Models\User;
use App\Support\Dashboard\DashboardKpis;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One /dashboard route; the page component depends on the role.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('viewDashboard');

        /** @var User $user */
        $user = $request->user();
        // Dashboard period (Phase 7C): 7, 30 (default) or 90 days.
        $days = in_array((int) $request->query('days'), DashboardKpis::PERIODS, true) ? (int) $request->query('days') : 30;

        return match ($user->getRole()) {
            Role::SuperAdmin => Inertia::render('dashboard/system', app(BuildSystemDashboard::class)($user)),
            Role::DirectorGeneral, Role::DeputyDirectorGeneral => Inertia::render('dashboard/national', app(BuildNationalDashboard::class)($user, $days)),
            Role::StateDirector, Role::DeputyStateDirector => Inertia::render('dashboard/state', app(BuildStateDashboard::class)($user, $days)),
            Role::LgaSecurityChief => Inertia::render('dashboard/lga', app(BuildLgaDashboard::class)($user, $days)),
            // viewDashboard already refuses users without a role.
            null => abort(403),
        };
    }
}
