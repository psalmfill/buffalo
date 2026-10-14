<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\GeopoliticalZone;
use App\Models\State;
use App\Support\Lens\StateLens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sets or clears the state lens (Phase 11A.2). The place must be within
 * the user's reach (StatePolicy / GeopoliticalZonePolicy::viewDashboard).
 */
class LensController extends Controller
{
    public function update(Request $request, StateLens $lens): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:state,zone'],
            'ulid' => ['required', 'string', 'size:26'],
        ]);

        $place = $data['type'] === 'state'
            ? State::query()->where('ulid', $data['ulid'])->firstOrFail()
            : GeopoliticalZone::query()->where('ulid', $data['ulid'])->firstOrFail();
        $this->authorize('viewDashboard', $place);

        $lens->set($request, $place);

        return redirect()->route($place instanceof State ? 'dashboard.states.show' : 'dashboard.zones.show', $place)
            ->with('toast', ['type' => 'success', 'message' => "Viewing {$place->name}. Lists, the map and analytics open on it."]);
    }

    public function destroy(Request $request, StateLens $lens): RedirectResponse
    {
        $this->authorize('useLens');
        $lens->clear($request);

        return redirect()->route('dashboard')->with('toast', ['type' => 'success', 'message' => 'Viewing all of Nigeria.']);
    }
}
