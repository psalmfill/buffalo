<?php

namespace App\Http\Controllers\Incidents;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\IncidentSavedFilter;
use App\Models\User;
use App\Support\Incidents\IncidentFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saved incident filters (Phase 5D): available to every `incidents.view`
 * holder, each managing only their own. Filters are validated through
 * IncidentFilters, so only known keys and values are kept.
 */
class IncidentSavedFilterController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Incident::class);
        $user = $this->actor($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('incident_saved_filters', 'name')->where('user_id', $user->id)],
            'filters' => ['present', 'array'],
        ]);

        $filter = new IncidentSavedFilter(['name' => trim($data['name']), 'filters' => IncidentFilters::from($data['filters'])->toArray()]);
        $filter->user_id = $user->id;
        $filter->save();

        return back()->with('toast', ['type' => 'success', 'message' => "Saved as \"{$filter->name}\"."]);
    }

    public function destroy(Request $request, string $savedFilter): RedirectResponse
    {
        $this->authorize('viewAny', Incident::class);

        // Only one's own: anyone else's is simply not found.
        IncidentSavedFilter::query()->where('user_id', $this->actor($request)->id)->where('ulid', $savedFilter)->firstOrFail()->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Saved filter removed.']);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
