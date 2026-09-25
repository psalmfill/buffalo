<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\BuildLgaDashboard;
use App\Actions\Dashboard\BuildNationalDashboard;
use App\Actions\Dashboard\BuildStateDashboard;
use App\Actions\Dashboard\BuildSystemDashboard;
use App\Enums\Role;
use App\Models\User;
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

        return match ($user->getRole()) {
            Role::SuperAdmin => Inertia::render('dashboard/system', app(BuildSystemDashboard::class)($user)),
            Role::DirectorGeneral, Role::DeputyDirectorGeneral => Inertia::render('dashboard/national', app(BuildNationalDashboard::class)($user)),
            Role::StateDirector, Role::DeputyStateDirector => Inertia::render('dashboard/state', app(BuildStateDashboard::class)($user)),
            Role::LgaSecurityChief => Inertia::render('dashboard/lga', app(BuildLgaDashboard::class)($user)),
            // viewDashboard already refuses users without a role.
            null => abort(403),
        };
    }
}
