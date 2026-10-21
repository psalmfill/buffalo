<?php

namespace App\Http\Controllers\PersonsOfInterest;

use App\Actions\PersonsOfInterest\ChangePoiStatus;
use App\Actions\PersonsOfInterest\DeletePersonOfInterest;
use App\Actions\PersonsOfInterest\DesignatePersonOfInterest;
use App\Actions\PersonsOfInterest\MarkPoiReviewed;
use App\Actions\PersonsOfInterest\SavePoiDetails;
use App\Enums\PoiStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PoiPresenter;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Http\Requests\PersonsOfInterest\PoiDetailsRequest;
use App\Http\Requests\PersonsOfInterest\PoiReviewRequest;
use App\Http\Requests\PersonsOfInterest\PoiStatusRequest;
use App\Models\Lga;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\State;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Exports\Lists\Exporters\PersonsOfInterestExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Persons of interest (Phase 3B): the list, designation, details, status
 * and risk changes (always with a comment), reviews and deletion.
 *
 * {poi} is bound through the POI's scopes: its jurisdiction and its
 * person's jurisdiction and classification. The details are shown on the
 * person page (POI tab, access-logged with context "poi").
 */
class PersonOfInterestController extends Controller
{
    public function index(Request $request, JurisdictionOptions $options, PersonsOfInterestExporter $exporter): Response
    {
        $this->authorize('viewAny', PersonOfInterest::class);
        $user = $this->actor($request);
        $states = $options->states($user);
        $filterOptions = PoiPresenter::options();

        $filters = $exporter->filters($request->query(), $user);
        $state = isset($filters['state']) ? $states->firstWhere('ulid', $filters['state']) : null;
        $lgas = $options->lgas($user, $state);

        // The same query as the (sensitive) export (Phase 11E).
        $results = $exporter->query($user, $filters)
            ->with(['person.lga', 'person.roles', 'person.media' => fn ($q) => $q->where('collection_name', Person::PHOTOS), 'lga'])
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('persons-of-interest/index', [
            'results' => $results->through(fn (PersonOfInterest $poi) => PoiPresenter::row($poi)),
            'filters' => [
                'risk' => $filters['risk'] ?? '',
                'status' => $filters['status'] ?? '',
                'offence' => $filters['offence'] ?? '',
                'state' => $filters['state'] ?? '',
                'zone' => $filters['zone'] ?? '',
                'lga' => $filters['lga'] ?? '',
                'overdue' => $filters['overdue'] ?? '',
            ],
            'options' => $filterOptions,
            'states' => $states->count() > 1 ? $states->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name])->values() : [],
            'zones' => PlaceFilter::options($user),
            'lgas' => $lgas->count() > 1 ? $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => $l->name])->values() : [],
        ]);
    }

    public function create(Person $person): Response|RedirectResponse
    {
        $this->authorize('create', [PersonOfInterest::class, $person]);

        if (PersonOfInterest::query()->where('person_id', $person->id)->exists()) {
            return redirect()->route('persons.show', ['person' => $person, 'tab' => 'poi']);
        }

        return $this->form($person, null);
    }

    public function store(PoiDetailsRequest $request, Person $person, DesignatePersonOfInterest $designate): RedirectResponse
    {
        $this->authorize('create', [PersonOfInterest::class, $person]);
        abort_if(PersonOfInterest::query()->where('person_id', $person->id)->exists(), 409, 'Already a person of interest.');

        $designate($person, $request->validated(), (string) $request->validated('comment'), $this->actor($request));

        return redirect()->route('persons.show', ['person' => $person, 'tab' => 'poi'])
            ->with('toast', ['type' => 'success', 'message' => 'Designated as a person of interest.']);
    }

    public function edit(PersonOfInterest $poi): Response
    {
        $this->authorize('update', $poi);

        $record = PersonOfInterest::query()->withCoordinates()->with('lga')->whereKey($poi->id)->firstOrFail();

        return $this->form(Person::query()->findOrFail($poi->person_id), $record);
    }

    public function update(PoiDetailsRequest $request, PersonOfInterest $poi, SavePoiDetails $save): RedirectResponse
    {
        $this->authorize('update', $poi);

        $save($poi, $request->validated(), $this->actor($request));

        return $this->toPoiTab($poi, 'Details saved.');
    }

    public function status(PoiStatusRequest $request, PersonOfInterest $poi, ChangePoiStatus $change): RedirectResponse
    {
        $this->authorize($request->status() === PoiStatus::Cleared ? 'clear' : 'update', $poi);

        $change($poi, $request->status(), $request->risk(), (string) $request->validated('comment'), $this->actor($request));

        return $this->toPoiTab($poi, 'Status saved.');
    }

    public function review(PoiReviewRequest $request, PersonOfInterest $poi, MarkPoiReviewed $review): RedirectResponse
    {
        $this->authorize('update', $poi);

        $next = $request->validated('next_review');
        $review($poi, (string) $request->validated('comment'), is_string($next) ? $next : null, $this->actor($request));

        return $this->toPoiTab($poi, 'Review recorded.');
    }

    public function destroy(DeleteRecordRequest $request, PersonOfInterest $poi, DeletePersonOfInterest $delete): RedirectResponse
    {
        $this->authorize('delete', $poi);

        $person = Person::query()->findOrFail($poi->person_id);
        $delete($poi, (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('persons.show', ['person' => $person, 'tab' => 'poi'])
            ->with('toast', ['type' => 'success', 'message' => 'Person of interest record deleted.']);
    }

    private function toPoiTab(PersonOfInterest $poi, string $message): RedirectResponse
    {
        return redirect()->route('persons.show', ['person' => Person::query()->findOrFail($poi->person_id), 'tab' => 'poi'])
            ->with('toast', ['type' => 'success', 'message' => $message]);
    }

    private function form(Person $person, ?PersonOfInterest $poi): Response
    {
        Inertia::encryptHistory();
        $person->loadMissing('lga');

        return Inertia::render('persons-of-interest/form', [
            'person' => ['ulid' => $person->ulid, 'name' => $person->name, 'lga' => $person->lga->name],
            'poi' => $poi === null ? null : [
                'ulid' => $poi->ulid,
                'offence_categories' => $poi->offence_categories,
                'known_offences' => $poi->known_offences,
                'modus_operandi' => $poi->modus_operandi,
                'latitude' => $poi->latitude,
                'longitude' => $poi->longitude,
                'last_known_location_description' => $poi->last_known_location_description,
                'last_sighted_at' => $poi->last_sighted_at?->format('Y-m-d\TH:i'),
            ],
            'options' => PoiPresenter::options(),
            'map' => MapView::for($person->lga),
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
