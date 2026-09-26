<?php

namespace App\Http\Controllers\CriminalGroups;

use App\Actions\CriminalGroups\ManageGroupAreas;
use App\Http\Controllers\Controller;
use App\Http\Requests\CriminalGroups\GroupAreaRequest;
use App\Models\CriminalGroup;
use App\Models\CriminalGroupArea;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A group's areas of operation: any LGA in any state. {group} is bound
 * through its scopes; {area} must belong to it.
 */
class GroupAreaController extends Controller
{
    /** LGAs of a state, for choosing an area (reference data). */
    public function lgas(CriminalGroup $group, State $state): JsonResponse
    {
        $this->authorize('update', $group);

        return response()->json([
            'lgas' => Lga::query()->active()->where('state_id', $state->id)->orderBy('name')->get(['ulid', 'name'])
                ->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => $l->name]),
        ]);
    }

    public function store(GroupAreaRequest $request, CriminalGroup $group, ManageGroupAreas $areas): RedirectResponse
    {
        $this->authorize('update', $group);

        /** @var Lga $lga */
        $lga = $request->lga();
        /** @var User $actor */
        $actor = $request->user();
        $notes = $request->validated('notes');
        $areas->add($group, $lga, is_string($notes) ? $notes : null, $actor);

        return redirect()->route('criminal-groups.show', ['group' => $group, 'tab' => 'areas'])
            ->with('toast', ['type' => 'success', 'message' => "Area added: {$lga->name} LGA."]);
    }

    public function destroy(Request $request, CriminalGroup $group, CriminalGroupArea $area, ManageGroupAreas $areas): RedirectResponse
    {
        $this->authorize('update', $group);
        abort_unless($area->criminal_group_id === $group->id, 404);

        /** @var User $actor */
        $actor = $request->user();
        $areas->remove($area, $actor);

        return redirect()->route('criminal-groups.show', ['group' => $group, 'tab' => 'areas'])
            ->with('toast', ['type' => 'success', 'message' => 'Area removed.']);
    }
}
