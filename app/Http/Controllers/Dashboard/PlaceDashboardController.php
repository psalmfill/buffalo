<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Dashboard\BuildNationalDashboard;
use App\Actions\Dashboard\BuildStateDashboard;
use App\Http\Controllers\Controller;
use App\Models\GeopoliticalZone;
use App\Models\State;
use App\Models\User;
use App\Support\Dashboard\DashboardKpis;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Lens\StateLens;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A national user's view of one state or one zone (Phase 11A.2).
 *
 * The state view is the dashboard a State Director of that state sees: the
 * same builder and components, run inside JurisdictionContext::focus() so
 * every scoped query is narrowed to the state. The focus only narrows: the
 * national user's own scope still applies, so a zoned DDG sees nothing
 * outside their zones (and the policy refuses those states anyway).
 * Actions on the page follow the national user's own permissions.
 */
class PlaceDashboardController extends Controller
{
    public function state(Request $request, State $state, JurisdictionContext $context): Response
    {
        $this->authorize('viewDashboard', $state);
        $user = $this->actor($request);
        $days = $this->days($request);

        $props = $context->focus([$state->id], fn () => app(BuildStateDashboard::class)($user, $days, $state));

        return Inertia::render('dashboard/state', [
            ...$props,
            'place' => ['type' => 'state', 'ulid' => $state->ulid, 'name' => $state->name],
        ]);
    }

    public function zone(Request $request, GeopoliticalZone $zone, JurisdictionContext $context, StateLens $lens): Response
    {
        $this->authorize('viewDashboard', $zone);
        $user = $this->actor($request);
        $days = $this->days($request);
        /** @var list<int> $stateIds */
        $stateIds = array_values(array_map('intval', $lens->states($user)->where('geopolitical_zone_id', $zone->id)->modelKeys()));

        $props = $context->focus($stateIds, fn () => app(BuildNationalDashboard::class)($user, $days, $zone));

        return Inertia::render('dashboard/national', [
            ...$props,
            'place' => ['type' => 'zone', 'ulid' => $zone->ulid, 'name' => $zone->name],
        ]);
    }

    private function days(Request $request): int
    {
        return in_array((int) $request->query('days'), DashboardKpis::PERIODS, true) ? (int) $request->query('days') : 30;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
