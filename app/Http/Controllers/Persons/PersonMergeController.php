<?php

namespace App\Http\Controllers\Persons;

use App\Actions\Persons\MergePersons;
use App\Actions\Persons\UndoPersonMerge;
use App\Enums\MergeRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MergePresenter;
use App\Http\Presenters\PersonPresenter;
use App\Http\Requests\Persons\MergePersonsRequest;
use App\Http\Requests\Persons\UndoPersonMergeRequest;
use App\Models\Community;
use App\Models\Person;
use App\Models\PersonMerge;
use App\Models\PersonMergeRequest;
use App\Models\User;
use App\Support\Auth\StepUp;
use App\Support\Jurisdiction\OfficerNames;
use App\Support\Persons\Merge\MergeBlocked;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review and merge (Phase 10A.7), undo (10A.6), and a merged record's
 * history. Both persons are loaded through the viewer's scopes (anything
 * else is 404) and authorised with PersonPolicy::merge (own jurisdiction
 * and clearance). Executing and undoing need step-up.
 */
class PersonMergeController extends Controller
{
    public function review(Request $request): Response
    {
        $survivor = $this->person((string) $request->query('a'));
        $source = $this->person((string) $request->query('b'));
        abort_if($survivor->id === $source->id, 404);
        $this->authorize('merge', $survivor);
        $this->authorize('merge', $source);

        $mergeRequest = $request->filled('request')
            ? PersonMergeRequest::query()->where('ulid', (string) $request->query('request'))->where('status', MergeRequestStatus::Pending->value)->first()
            : null;
        abort_if($mergeRequest !== null && (! $mergeRequest->involves($survivor->id) || ! $mergeRequest->involves($source->id)), 404);

        // Both records are read in full: access-logged like a person page.
        $survivor->recordAccess(context: 'merge_review');
        $source->recordAccess(context: 'merge_review');
        Inertia::encryptHistory();

        $community = fn (Person $person): ?array => $person->community === null ? null : ['name' => $person->community->name, 'lga' => Community::query()->whereKey($person->community_id)->with('lga:id,ulid')->first()?->lga?->ulid];

        return Inertia::render('persons/merge', [
            ...MergePresenter::review($survivor, $source),
            // Which LGA each community is in: a community follows the owning LGA.
            'communities' => ['survivor' => $community($survivor), 'source' => $community($source)],
            'request' => $mergeRequest === null ? null : [
                'ulid' => $mergeRequest->ulid,
                'reason' => $mergeRequest->reason,
                'requestedBy' => (string) OfficerNames::for([$mergeRequest->requested_by])->get($mergeRequest->requested_by),
            ],
        ]);
    }

    public function store(MergePersonsRequest $request, MergePersons $merge): RedirectResponse
    {
        $survivor = $this->person((string) $request->validated('survivor'));
        $source = $this->person((string) $request->validated('source'));
        $this->authorize('merge', $survivor);
        $this->authorize('merge', $source);
        StepUp::ensure($request);

        $mergeRequest = null;
        if ($request->validated('request') !== null) {
            $mergeRequest = PersonMergeRequest::query()->where('ulid', (string) $request->validated('request'))->firstOrFail();
            $this->authorize('decide', $mergeRequest);
        }

        try {
            $merge($survivor, $source, $request->choices(), $this->actor($request), $mergeRequest);
        } catch (MergeBlocked $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return redirect()->route('persons.show', $survivor)
            ->with('toast', ['type' => 'success', 'message' => 'The records were merged. The merge can be undone for '.config('buffalo.merges.undo_days').' days.']);
    }

    public function undo(UndoPersonMergeRequest $request, PersonMerge $merge, UndoPersonMerge $undo): RedirectResponse
    {
        $this->authorize('undo', $merge);
        StepUp::ensure($request);

        try {
            $undo($merge, $this->actor($request), (string) $request->validated('reason'), $request->keep());
        } catch (MergeBlocked $e) {
            throw ValidationException::withMessages(['undo' => $e->getMessage()]);
        }

        $survivor = Person::query()->whereKey($merge->survivor_person_id)->first();

        return ($survivor === null ? redirect()->route('persons.index') : redirect()->route('persons.show', $survivor))
            ->with('toast', ['type' => 'success', 'message' => 'The merge was undone. Both records exist again; revoked access and cancelled requests were not restored.']);
    }

    /**
     * The merged record's history, reachable from the survivor's page:
     * for viewers of the survivor in their own jurisdiction (never under a
     * grant). The source's classification never exceeds the survivor's.
     */
    public function history(Request $request, PersonMerge $merge): Response
    {
        $this->authorize('view', $merge);
        $survivor = Person::query()->whereKey($merge->survivor_person_id)->firstOrFail();
        $this->authorize('update', $survivor);
        $source = Person::withTrashed()->whereKey($merge->source_person_id)->firstOrFail();

        $source->recordAccess(context: 'merged_history');
        Inertia::encryptHistory();

        return Inertia::render('persons/merged-history', [
            'merge' => ['ulid' => $merge->ulid, 'executedAt' => $merge->executed_at->toIso8601String(), 'undoneAt' => $merge->undone_at?->toIso8601String()],
            'source' => ['ulid' => $source->ulid, 'name' => $source->name, 'merged' => $source->trashed()],
            'survivor' => PersonPresenter::row($survivor),
            'history' => PersonController::historyOf($source),
        ]);
    }

    private function person(string $ulid): Person
    {
        abort_unless(preg_match('/^[0-9A-Za-z]{26}$/', $ulid) === 1, 404);

        return Person::query()->with(['lga', 'state', 'community', 'roles', 'media' => fn ($q) => $q->where('collection_name', Person::PHOTOS)])
            ->where('ulid', strtolower($ulid))->firstOrFail();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
