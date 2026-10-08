<?php

namespace App\Http\Controllers\Persons;

use App\Actions\Persons\DeclinePersonMergeRequest;
use App\Actions\Persons\RequestPersonMerge;
use App\Enums\MergeRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MergePresenter;
use App\Http\Requests\Persons\ReasonRequest;
use App\Http\Requests\Persons\StorePersonMergeRequest;
use App\Models\Person;
use App\Models\PersonMergeRequest;
use App\Models\User;
use App\Support\Persons\Merge\MergeBlocked;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Merge requests (Phase 10A.7): raised by LGA chiefs and Deputy State
 * Directors on their own records, listed within the viewer's
 * jurisdiction, executed through the review page or declined with a note.
 */
class PersonMergeRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PersonMergeRequest::class);
        $actor = $this->actor($request);
        $status = MergeRequestStatus::tryFrom((string) $request->query('status')) ?? MergeRequestStatus::Pending;

        $requests = PersonMergeRequest::query()
            ->with(['personA.lga', 'personA.roles', 'personB.lga', 'personB.roles', 'lga'])
            ->where('status', $status->value)
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PersonMergeRequest $r) => MergePresenter::request($r, $actor));

        Inertia::encryptHistory();

        return Inertia::render('persons/merge-requests', [
            'requests' => $requests,
            'status' => $status->value,
            'statuses' => array_map(fn (MergeRequestStatus $s) => ['value' => $s->value, 'label' => $s->label()], MergeRequestStatus::cases()),
        ]);
    }

    public function store(StorePersonMergeRequest $request, RequestPersonMerge $requestMerge): RedirectResponse
    {
        $this->authorize('create', PersonMergeRequest::class);
        $actor = $this->actor($request);
        $person = $this->ownPerson($actor, (string) $request->validated('person'));
        $other = $this->ownPerson($actor, (string) $request->validated('other'));
        $this->authorize('requestMerge', $person);
        $this->authorize('requestMerge', $other);
        $suggested = match ($request->validated('suggested_survivor')) {
            $person->ulid => $person,
            $other->ulid => $other,
            default => null,
        };

        try {
            $requestMerge($actor, $person, $other, $suggested, (string) $request->validated('reason'));
        } catch (MergeBlocked $e) {
            throw ValidationException::withMessages(['other' => $e->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Merge requested. The State Director will review it.']);
    }

    public function decline(ReasonRequest $request, PersonMergeRequest $mergeRequest, DeclinePersonMergeRequest $decline): RedirectResponse
    {
        $this->authorize('decide', $mergeRequest);

        $decline($mergeRequest, $this->actor($request), (string) $request->validated('reason'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Merge request declined.']);
    }

    /** A record in the user's OWN jurisdiction (never one seen under a grant), else 404. */
    private function ownPerson(User $actor, string $ulid): Person
    {
        return Person::query()->ownJurisdiction($actor)->where('ulid', strtolower($ulid))->firstOrFail();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
