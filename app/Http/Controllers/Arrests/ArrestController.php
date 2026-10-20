<?php

namespace App\Http\Controllers\Arrests;

use App\Actions\Arrests\SaveArrest;
use App\Actions\CommunityInformation\DeleteWithReason;
use App\Enums\ArrestStatus;
use App\Enums\Gender;
use App\Enums\IncidentStatus;
use App\Enums\PersonInvolvement;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\ArrestPresenter;
use App\Http\Requests\Arrests\ArrestRequest;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Models\AgencyFormation;
use App\Models\Arrest;
use App\Models\Community;
use App\Models\Incident;
use App\Models\IncidentAgency;
use App\Models\IncidentPerson;
use App\Models\Lga;
use App\Models\OffenceType;
use App\Models\RecordAccessRequest;
use App\Models\SecurityAgency;
use App\Models\State;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Arrests (Phase 11C). {arrest} is bound through the jurisdiction and
 * classification scopes. Views above `restricted` are access-logged.
 */
class ArrestController extends Controller
{
    private const TABS = ['overview', 'timeline', 'history'];

    public function index(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('viewAny', Arrest::class);
        $user = $this->actor($request);
        $states = $options->states($user);

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'agency' => ['nullable', 'string', 'size:26'],
            'formation' => ['nullable', 'string', 'size:26'],
            'offence' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::enum(ArrestStatus::class)],
            'state' => ['nullable', 'string', Rule::in($states->pluck('ulid'))],
            'zone' => PlaceFilter::zoneRule($user),
            'lga' => ['nullable', 'string', 'size:26'],
            'linked' => ['nullable', Rule::in(['yes', 'no'])],
            'poi' => ['nullable', Rule::in(['yes', 'no'])],
        ]);
        $state = isset($filters['state']) ? $states->firstWhere('ulid', $filters['state']) : null;
        $zone = PlaceFilter::zone($filters['zone'] ?? null);
        $lgas = $options->lgas($user, $state);
        $lga = isset($filters['lga']) ? $lgas->firstWhere('ulid', $filters['lga']) : null;
        $tz = (string) config('app.timezone');
        $poiPersons = fn ($q) => $q->select('arrest_persons.arrest_id')->from('arrest_persons')
            ->join('persons_of_interest', 'persons_of_interest.person_id', '=', 'arrest_persons.person_id')
            ->whereNull('arrest_persons.deleted_at')->whereNull('persons_of_interest.deleted_at');

        $arrests = Arrest::query()
            ->with(['agency', 'lga', 'incident', 'persons.offences.offenceType'])
            ->when($filters['from'] ?? null, fn (Builder $q, string $v) => $q->where('arrests.arrested_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $v, $tz)?->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $v) => $q->where('arrests.arrested_at', '<', CarbonImmutable::createFromFormat('Y-m-d', $v, $tz)?->addDay()->startOfDay()))
            ->when($filters['agency'] ?? null, fn (Builder $q, string $v) => $q->whereIn('arrests.security_agency_id', SecurityAgency::query()->where('ulid', $v)->select('id')))
            ->when($filters['formation'] ?? null, fn (Builder $q, string $v) => $q->whereIn('arrests.agency_formation_id', AgencyFormation::query()->where('ulid', $v)->select('agency_formations.id')))
            ->when($filters['offence'] ?? null, fn (Builder $q, string $v) => $q->whereHas('persons.offences', fn (Builder $o) => $o->whereIn('offence_type_id', OffenceType::query()->where('ulid', $v)->select('id'))))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('arrests.status', $v))
            ->when($state, fn (Builder $q) => $q->where('arrests.state_id', $state?->id))
            ->tap(fn (Builder $q) => PlaceFilter::apply($q, 'arrests.state_id', $zone))
            ->when($lga, fn (Builder $q) => $q->where('arrests.lga_id', $lga?->id))
            ->when(($filters['linked'] ?? null) === 'yes', fn (Builder $q) => $q->whereNotNull('arrests.incident_id'))
            ->when(($filters['linked'] ?? null) === 'no', fn (Builder $q) => $q->whereNull('arrests.incident_id'))
            ->when(($filters['poi'] ?? null) === 'yes', fn (Builder $q) => $q->whereIn('arrests.id', $poiPersons))
            ->when(($filters['poi'] ?? null) === 'no', fn (Builder $q) => $q->whereNotIn('arrests.id', $poiPersons))
            ->orderByDesc('arrests.arrested_at')->orderByDesc('arrests.id')
            ->paginate(25)->withQueryString();

        return Inertia::render('arrests/index', [
            'arrests' => $arrests->through(fn (Arrest $a) => ArrestPresenter::row($a)),
            'filters' => [
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'agency' => $filters['agency'] ?? '',
                'formation' => $filters['formation'] ?? '',
                'offence' => $filters['offence'] ?? '',
                'status' => $filters['status'] ?? '',
                'state' => $filters['state'] ?? '',
                'zone' => $filters['zone'] ?? '',
                'lga' => $filters['lga'] ?? '',
                'linked' => $filters['linked'] ?? '',
                'poi' => $filters['poi'] ?? '',
            ],
            'options' => ArrestPresenter::options(),
            'formations' => AgencyFormation::query()->orderBy('name')->limit(500)->get(['ulid', 'name'])->map(fn (AgencyFormation $f) => ['value' => $f->ulid, 'label' => $f->name])->values(),
            'states' => $states->count() > 1 ? $states->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name])->values() : [],
            'zones' => PlaceFilter::options($user),
            'lgas' => $lgas->count() > 1 ? $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => $l->name])->values() : [],
            'can' => ['create' => $user->can('create', Arrest::class)],
        ]);
    }

    public function create(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('create', Arrest::class);
        $user = $this->actor($request);

        $incident = null;
        if (is_string($request->query('incident'))) {
            $incident = Incident::query()->withCoordinates()->where('incidents.ulid', $request->query('incident'))->first();
            abort_if($incident === null, 404);
            $this->authorize('linkIncident', [Arrest::class, $incident]);
        }

        return $this->form($user, $options, null, $incident, $request->query('lga'));
    }

    public function store(ArrestRequest $request, SaveArrest $save): RedirectResponse
    {
        $this->authorize('create', Arrest::class);

        /** @var Lga $lga */
        $lga = $request->lga();
        $arrest = $save->create($lga, $request->values(), $this->actor($request));

        return redirect()->route('arrests.show', $arrest)
            ->with('toast', ['type' => 'success', 'message' => "Arrest {$arrest->reference_no} recorded."]);
    }

    public function show(Request $request, Arrest $arrest): Response
    {
        $this->authorize('view', $arrest);
        $user = $this->actor($request);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'overview';

        if ($arrest->logsViews()) {
            $arrest->recordAccess(context: "tab:{$tab}");
        }

        Inertia::encryptHistory();

        $row = Arrest::query()->withCoordinates()->whereKey($arrest->id)->firstOrFail();
        $row->load(['agency', 'formation', 'lga', 'state', 'community', 'incident']);

        return Inertia::render('arrests/show', [
            'arrest' => ArrestPresenter::detail($row),
            'persons' => ArrestPresenter::persons($row, $user),
            'comparison' => $row->incident !== null ? ArrestPresenter::comparison($row->incident) : null,
            'tab' => $tab,
            'map' => MapView::for($row->lga),
            'timeline' => $tab === 'timeline' ? ArrestPresenter::timeline($row) : null,
            'history' => $tab === 'history'
                ? Activity::query()->with(['causer', 'subject'])
                    ->where('subject_type', $arrest->getMorphClass())->where('subject_id', $arrest->id)
                    ->latest('id')
                    ->paginate(25, pageName: 'history_page')
                    ->withQueryString()
                    ->through(fn (Activity $a) => ActivityPresenter::present($a))
                : null,
            'options' => ArrestPresenter::options(),
            'can' => [
                'update' => $user->can('update', $arrest),
                'delete' => $user->can('delete', $arrest),
                'amendIncident' => $row->incident !== null && $user->can('update', $row->incident),
            ],
        ]);
    }

    public function edit(Request $request, Arrest $arrest, JurisdictionOptions $options): Response
    {
        $this->authorize('update', $arrest);

        return $this->form($this->actor($request), $options, $arrest, null, $request->query('lga'));
    }

    public function update(ArrestRequest $request, Arrest $arrest, SaveArrest $save): RedirectResponse
    {
        $this->authorize('update', $arrest);

        $save->update($arrest, $request->lga(), $request->values(), $this->actor($request));

        return redirect()->route('arrests.show', $arrest)->with('toast', ['type' => 'success', 'message' => 'Arrest saved.']);
    }

    public function destroy(DeleteRecordRequest $request, Arrest $arrest, DeleteWithReason $delete): RedirectResponse
    {
        $this->authorize('delete', $arrest);

        $reference = $arrest->reference_no;
        $delete($arrest, 'arrest', (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('arrests.index')->with('toast', ['type' => 'success', 'message' => "{$reference} deleted."]);
    }

    private function form(User $user, JurisdictionOptions $options, ?Arrest $arrest, ?Incident $incident, mixed $lgaUlid): Response
    {
        $row = $arrest === null ? null : Arrest::query()->withCoordinates()->whereKey($arrest->id)->firstOrFail();
        $row?->load(['agency', 'formation', 'lga', 'state', 'community', 'incident']);
        $lgas = $options->lgas($user)->load('state:id,ulid,name');
        $lga = ($row !== null ? $row->lga : null)
            ?? ($incident !== null ? $lgas->firstWhere('id', $incident->lga_id) : null)
            ?? (is_string($lgaUlid) ? $lgas->firstWhere('ulid', $lgaUlid) : null)
            ?? ($lgas->count() === 1 ? $lgas->first() : null);

        return Inertia::render('arrests/form', [
            'arrest' => $row === null ? null : ArrestPresenter::detail($row),
            'persons' => $row === null ? [] : ArrestPresenter::persons($row, $user),
            'prefill' => $incident === null ? null : $this->prefill($incident),
            'lga' => $lga === null ? null : ['ulid' => $lga->ulid, 'name' => $lga->name],
            'lgas' => $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => "{$l->name} ({$l->state->name})"])->values(),
            'communities' => $lga === null ? [] : Community::query()->where('lga_id', $lga->id)->orderBy('name')->get(['ulid', 'name'])->map(fn (Community $c) => ['value' => $c->ulid, 'label' => $c->name]),
            'formations' => AgencyFormation::query()->with('agency')->orderBy('name')->limit(1000)->get()
                ->map(fn (AgencyFormation $f) => ['value' => $f->ulid, 'label' => $f->name, 'agency' => $f->agency->ulid])->values(),
            'options' => ArrestPresenter::options($row),
            'genders' => array_map(fn ($g) => ['value' => $g->value, 'label' => $g->label()], Gender::cases()),
            'map' => MapView::for($lga),
            'can' => [
                'checkRecords' => $user->can('create', RecordAccessRequest::class),
                'pickIncident' => $user->can('viewAny', Incident::class),
            ],
        ]);
    }

    /**
     * What the incident knows (Phase 11C.3): link, place, community and
     * location; agency choices from its responding agencies (first chosen);
     * its suspects as suggestions; its date as a hint only.
     *
     * @return array<string, mixed>
     */
    private function prefill(Incident $incident): array
    {
        $incident->loadMissing(['lga', 'community']);
        $agencies = IncidentAgency::query()->where('incident_id', $incident->id)->with(['agency', 'formation'])->orderBy('id')->get();
        $suspects = IncidentPerson::query()->where('incident_id', $incident->id)
            ->where('involvement', PersonInvolvement::Suspect->value)->with('person')->orderBy('id')->get();

        return [
            'incident' => ['value' => $incident->ulid, 'label' => trim(($incident->reference_no ?? 'Draft').' · '.$incident->title)],
            'lga' => $incident->lga->ulid,
            'community' => $incident->community?->ulid,
            'latitude' => $incident->latitude,
            'longitude' => $incident->longitude,
            'location_description' => $incident->location_description,
            'agencies' => array_values($agencies->unique('security_agency_id')->map(fn (IncidentAgency $a) => [
                'value' => $a->agency->ulid,
                'label' => $a->agency->name,
                'formation' => $a->formation?->ulid,
            ])->all()),
            'suspects' => array_values($suspects->map(fn (IncidentPerson $p) => $p->person !== null
                ? ['person' => ['value' => $p->person->ulid, 'label' => $p->person->name], 'unidentified_description' => null]
                : ['person' => null, 'unidentified_description' => $p->person_id === null ? $p->unidentified_description : null])
                ->filter(fn (array $s) => $s['person'] !== null || $s['unidentified_description'] !== null)->values()->all()),
            'occurred_at' => $incident->occurred_at->toIso8601String(),
            'incident_status' => $incident->status === IncidentStatus::Draft ? 'draft' : null,
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
