<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Models\RecordAccessRequest;
use App\Models\User;
use App\Services\Search\GlobalSearch;
use App\Support\Lens\StateLens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Global search (Phase 7B): the header's quick search (JSON) and the full
 * results page. Intelligence roles only (`globalSearch`); rate limited
 * (`throttle:global-search`). Results come through each module's scopes.
 */
class SearchController extends Controller
{
    public function index(Request $request, GlobalSearch $search, StateLens $lens): Response
    {
        $this->authorize('globalSearch');
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', 'string', 'max:32'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        // Results may name persons: keep them out of the browser history.
        Inertia::encryptHistory();

        return Inertia::render('search/index', [
            'results' => $search->full($this->actor($request), (string) ($data['q'] ?? ''), $data['type'] ?? null, (int) ($data['page'] ?? 1), $lens->stateIds($request)),
            'can' => ['crossJurisdiction' => $this->actor($request)->can('create', RecordAccessRequest::class)],
        ]);
    }

    public function quick(Request $request, GlobalSearch $search, StateLens $lens): JsonResponse
    {
        $this->authorize('globalSearch');
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200']]);

        return response()->json($search->quick($this->actor($request), (string) ($data['q'] ?? ''), $lens->stateIds($request)));
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
