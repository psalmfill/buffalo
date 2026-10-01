<?php

namespace App\Http\Controllers\Map;

use App\Actions\Blackspots\DismissBlackspotSuggestion;
use App\Actions\Blackspots\GenerateBlackspotSuggestions;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BlackspotSuggestionRows;
use App\Models\BlackspotSuggestion;
use App\Models\Flashpoint;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Suggested blackspots (Phase 6C): the list (open suggestions the viewer can
 * see, counts through their own scopes), Dismiss with a reason, and
 * Refresh (SD and above). Accepting goes through the flashpoint form
 * (/flashpoints/create?suggestion=…).
 */
class BlackspotSuggestionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', BlackspotSuggestion::class);
        /** @var User $user */
        $user = $request->user();
        $open = $request->query('open');
        $selected = is_string($open) ? BlackspotSuggestion::query()->where('ulid', $open)->first() : null;

        return Inertia::render('blackspots/index', [
            'suggestions' => BlackspotSuggestionRows::rows(BlackspotSuggestion::query()->where('blackspot_suggestions.status', 'open')),
            'open' => $selected === null ? null : [
                'ulid' => $selected->ulid,
                'members' => BlackspotSuggestionRows::members($selected),
            ],
            'settings' => [
                'eps_meters' => (int) config('buffalo.blackspots.eps_meters'),
                'min_points' => (int) config('buffalo.blackspots.min_points'),
                'days' => (int) config('buffalo.blackspots.days'),
            ],
            'can' => [
                'refresh' => $user->can('refresh', BlackspotSuggestion::class),
                'accept' => $user->can('create', Flashpoint::class),
            ],
        ]);
    }

    public function dismiss(Request $request, BlackspotSuggestion $suggestion, DismissBlackspotSuggestion $dismiss): RedirectResponse
    {
        $this->authorize('decide', $suggestion);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        /** @var User $user */
        $user = $request->user();

        $dismiss($suggestion, trim((string) $data['reason']), $user);

        return back()->with('toast', ['type' => 'success', 'message' => 'Suggestion dismissed.']);
    }

    public function refresh(Request $request, JurisdictionOptions $options, GenerateBlackspotSuggestions $generate): RedirectResponse
    {
        $this->authorize('refresh', BlackspotSuggestion::class);
        /** @var User $user */
        $user = $request->user();

        $new = 0;
        foreach ($options->states($user) as $state) {
            $new += $generate($state->id)['new'];
        }

        return back()->with('toast', ['type' => 'success', 'message' => $new === 1 ? '1 new suggestion.' : "{$new} new suggestions."]);
    }
}
