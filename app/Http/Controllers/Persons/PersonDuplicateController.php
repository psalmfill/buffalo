<?php

namespace App\Http\Controllers\Persons;

use App\Actions\Persons\DismissDuplicatePair;
use App\Enums\MergeRequestStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MergePresenter;
use App\Http\Presenters\PersonPresenter;
use App\Http\Requests\Persons\ReasonRequest;
use App\Models\Person;
use App\Models\PersonMergeRequest;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Persons\Merge\DuplicateCounts;
use App\Support\Persons\Merge\PersonDuplicateFinder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Duplicate candidates (Phase 10A.3): pairs of the user's OWN records that
 * may be the same person. National users filter by state; State Directors
 * and deputies see their state; LGA chiefs their LGA.
 */
class PersonDuplicateController extends Controller
{
    public function index(Request $request, PersonDuplicateFinder $finder, JurisdictionOptions $options): Response
    {
        $this->authorize('viewDuplicates', Person::class);
        $actor = $this->actor($request);

        $states = $options->states($actor);
        $state = $states->count() > 1
            ? $states->firstWhere('ulid', (string) $request->query('state')) ?? $states->first()
            : $states->first();

        $pairs = $finder->forUser($actor, fn (Builder $q) => $q->where('persons.state_id', $state === null ? 0 : $state->id));

        $pending = PersonMergeRequest::query()
            ->where('status', MergeRequestStatus::Pending->value)
            ->get(['ulid', 'person_a_id', 'person_b_id'])
            ->mapWithKeys(fn (PersonMergeRequest $r) => [min($r->person_a_id, $r->person_b_id).'|'.max($r->person_a_id, $r->person_b_id) => $r->ulid])
            ->all();

        Inertia::encryptHistory();

        return Inertia::render('persons/duplicates', [
            'pairs' => array_map(fn (array $pair) => MergePresenter::pair($pair, $actor, $pending), $pairs),
            'limit' => (int) config('buffalo.merges.candidates_limit', 200),
            'states' => $states->count() > 1 ? $states->map(fn ($s) => ['ulid' => $s->ulid, 'name' => $s->name])->values() : [],
            'state' => $state === null ? null : ['ulid' => $state->ulid, 'name' => $state->name],
            'nightly' => $state === null ? null : DuplicateCounts::forState($state->id),
            'can' => [
                'request' => $actor->can('create', PersonMergeRequest::class),
                'merge' => $actor->checkPermissionTo(Permission::PersonsMerge->value),
            ],
            'options' => PersonPresenter::options(),
        ]);
    }

    /** "Not a duplicate", with a reason. */
    public function dismiss(ReasonRequest $request, DismissDuplicatePair $dismiss): RedirectResponse
    {
        $a = Person::query()->where('ulid', (string) $request->validated('a'))->firstOrFail();
        $b = Person::query()->where('ulid', (string) $request->validated('b'))->firstOrFail();
        $this->authorize('merge', $a);
        $this->authorize('merge', $b);

        $dismiss($this->actor($request), $a, $b, (string) $request->validated('reason'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Marked as not a duplicate. The pair will not be suggested again.']);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
