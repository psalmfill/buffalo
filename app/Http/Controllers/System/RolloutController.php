<?php

namespace App\Http\Controllers\System;

use App\Actions\Rollout\SetStateLive;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\SetStateLiveRequest;
use App\Models\State;
use App\Models\User;
use App\Support\Auth\StepUp;
use App\Support\Onboarding\OnboardingStats;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pilot rollout (Phase 9B.3): which states are live, and onboarding
 * progress per state. Super admins only; switching needs step-up and a
 * reason.
 */
class RolloutController extends Controller
{
    public function index(OnboardingStats $stats): Response
    {
        $this->authorize('manageRollout', State::class);

        return Inertia::render('system/rollout', ['states' => $stats->perState()]);
    }

    public function update(SetStateLiveRequest $request, State $state, SetStateLive $setLive): RedirectResponse
    {
        $this->authorize('manageRollout', State::class);
        StepUp::ensure($request);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $setLive($state, $request->boolean('live'), $request->string('reason')->toString(), $actor);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $state->is_live ? "{$state->name} is now live." : "{$state->name} is no longer live.",
        ]);
    }
}
