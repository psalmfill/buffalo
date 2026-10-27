<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\State;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\StateLens;
use Illuminate\Auth\Access\Response;

/**
 * States are reference data. Actions: the pilot rollout switch (Phase
 * 9B.3; super admins only), a national user's view of one state's
 * dashboard or the state lens (Phase 11A.2; states within reach), and the
 * state pages and profiles (Phase 11F).
 *
 * State pages: states within reach (JurisdictionOptions); any other state
 * is 404, like an LGA outside the jurisdiction. An LGA chief opens their
 * own state's page with limited tabs (viewOperational is false for them).
 */
class StatePolicy
{
    public function __construct(private readonly JurisdictionOptions $jurisdiction) {}

    public function viewDashboard(User $user, State $state): bool
    {
        return $user->checkPermissionTo(Permission::IncidentsView->value) && app(StateLens::class)->canChooseState($user, $state);
    }

    public function manageRollout(User $user): bool
    {
        return $user->checkPermissionTo(Permission::SystemSettings->value);
    }

    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::StatesView->value);
    }

    public function view(User $user, State $state): Response
    {
        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        return $this->jurisdiction->coversState($user, $state) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * The tabs and figures beyond the reference profile (security presence,
     * organizations, incidents, history, state-wide figures): users whose
     * jurisdiction covers the whole state, never an LGA chief.
     */
    public function viewOperational(User $user, State $state): bool
    {
        return $this->viewAny($user) && $this->jurisdiction->coversWholeState($user, $state);
    }

    /**
     * Edit the profile and capital, and mark the profile as reviewed: whole-
     * state jurisdiction (never an LGA chief). Outside reach: 404.
     */
    public function updateProfile(User $user, State $state): Response
    {
        if (! $user->checkPermissionTo(Permission::StateProfilesUpdate->value)) {
            return Response::deny();
        }

        return $this->jurisdiction->coversWholeState($user, $state) ? Response::allow() : Response::denyAsNotFound();
    }
}
