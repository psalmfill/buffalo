<?php

namespace App\Http\Controllers\Incidents;

use App\Actions\Incidents\SaveIncident;
use App\Enums\IncidentStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\IncidentPresenter;
use App\Http\Presenters\PersonPresenter;
use App\Http\Requests\Incidents\IncidentRequest;
use App\Models\Incident;
use App\Models\IncidentAgency;
use App\Models\IncidentCasualty;
use App\Models\IncidentChild;
use App\Models\IncidentCriminalGroup;
use App\Models\IncidentPerson;
use App\Models\IncidentProperty;
use App\Models\Lga;
use App\Models\RecordAccessRequest;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Incidents (Phase 5A): drafts are created and edited here; the workflow
 * (5C) and the full list and detail page (5D) come later. {incident} is
 * bound through the jurisdiction and classification scopes. Views of
 * confidential and secret incidents are logged.
 */
class IncidentController extends Controller
{
    /** Child models whose activity appears in the incident's history. */
    private const CHILDREN = [IncidentCasualty::class, IncidentPerson::class, IncidentCriminalGroup::class, IncidentAgency::class, IncidentProperty::class];

    /** The user's own drafts (the full list arrives in 5D). */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Incident::class);
        $user = $this->actor($request);

        $drafts = Incident::query()->with(['type', 'lga', 'state'])
            ->where('created_by', $user->id)
            ->where('status', IncidentStatus::Draft->value)
            ->latest('updated_at')
            ->paginate(25);

        return Inertia::render('incidents/index', [
            'incidents' => $drafts->through(fn (Incident $i) => IncidentPresenter::row($i)),
            'can' => ['create' => $user->can('create', Incident::class)],
        ]);
    }

    public function create(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('create', Incident::class);

        return $this->form($this->actor($request), $options, null);
    }

    public function store(IncidentRequest $request, SaveIncident $save): RedirectResponse
    {
        /** @var Lga $lga */
        $lga = $request->lga();
        $this->authorize('createIn', [Incident::class, $lga]);

        $incident = $save->create($lga, $request->values(), $this->actor($request));

        return redirect()->route('incidents.show', $incident)->with('toast', ['type' => 'success', 'message' => 'Draft saved.']);
    }

    public function show(Request $request, Incident $incident): Response
    {
        $this->authorize('view', $incident);
        $user = $this->actor($request);
        $tab = $request->query('tab') === 'history' ? 'history' : 'overview';

        if ($incident->logsViews()) {
            $incident->recordAccess(context: "tab:{$tab}");
        }
        Inertia::encryptHistory();

        return Inertia::render('incidents/show', [
            ...$this->props($incident, $user),
            'tab' => $tab,
            'history' => $tab === 'history' ? $this->history($incident) : null,
            'can' => ['update' => $user->can('update', $incident)],
        ]);
    }

    public function edit(Request $request, Incident $incident, JurisdictionOptions $options): Response
    {
        $this->authorize('update', $incident);

        return $this->form($this->actor($request), $options, $incident);
    }

    public function update(IncidentRequest $request, Incident $incident, SaveIncident $save): RedirectResponse
    {
        $this->authorize('update', $incident);
        /** @var Lga $lga */
        $lga = $request->lga();

        // Moving the draft to another LGA needs that LGA to be within reach.
        if ($lga->id !== $incident->lga_id) {
            $this->authorize('createIn', [Incident::class, $lga]);
        }

        $save->update($incident, $lga, $request->values(), $this->actor($request));

        return redirect()->route('incidents.show', $incident)->with('toast', ['type' => 'success', 'message' => 'Draft saved.']);
    }

    /**
     * The incident and its related rows, each through its own scopes.
     *
     * @return array<string, mixed>
     */
    private function props(Incident $incident, User $user): array
    {
        $row = Incident::query()->withCoordinates()->whereKey($incident->id)->firstOrFail();
        $row->load(['type', 'lga', 'state', 'community']);

        return [
            'incident' => IncidentPresenter::detail($row),
            'casualties' => IncidentCasualty::query()->with('agency')->where('incident_id', $incident->id)->orderBy('id')->get()
                ->map(fn (IncidentCasualty $c) => IncidentPresenter::casualty($c))->values(),
            'persons' => IncidentPerson::query()->with('person.personOfInterest')->where('incident_id', $incident->id)->orderBy('id')->get()
                ->map(fn (IncidentPerson $p) => IncidentPresenter::person($p, $user))->values(),
            'groups' => IncidentCriminalGroup::query()->with('group')->where('incident_id', $incident->id)->orderBy('id')->get()
                ->map(fn (IncidentCriminalGroup $g) => IncidentPresenter::group($g))->values(),
            'agencies' => IncidentAgency::query()->with(['agency', 'formation'])->where('incident_id', $incident->id)->orderBy('id')->get()
                ->map(fn (IncidentAgency $a) => IncidentPresenter::agency($a))->values(),
            'properties' => IncidentProperty::query()->with(['propertyType', 'site'])->where('incident_id', $incident->id)->orderBy('id')->get()
                ->map(fn (IncidentProperty $p) => IncidentPresenter::property($p))->values(),
            'map' => MapView::for($row->lga),
        ];
    }

    private function form(User $user, JurisdictionOptions $options, ?Incident $incident): Response
    {
        $lgas = $options->lgaQuery($user)->active()->with('state:id,ulid,name')->orderBy('name')->get();
        $incident?->load('type');

        return Inertia::render('incidents/form', [
            ...($incident === null ? ['incident' => null] : $this->props($incident, $user)),
            'lgas' => $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => "{$l->name} ({$l->state->name})"])->values(),
            'defaultLga' => $lgas->count() === 1 ? $lgas->first()?->ulid : null,
            'options' => IncidentPresenter::options($incident?->type),
            'genders' => PersonPresenter::options()['genders'],
            'canCheckRecords' => $user->can('create', RecordAccessRequest::class),
            'map' => MapView::for($incident?->lga),
        ]);
    }

    /**
     * The incident's history, including changes to its related rows the
     * viewer can see.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function history(Incident $incident): LengthAwarePaginator
    {
        return Activity::query()->with(['causer', 'subject'])
            ->where(function (Builder $q) use ($incident): void {
                $q->where(fn (Builder $s) => $s->where('subject_type', $incident->getMorphClass())->where('subject_id', $incident->id));

                foreach (self::CHILDREN as $class) {
                    /** @var class-string<IncidentChild> $class */
                    $ids = $class::withTrashed()->where('incident_id', $incident->id)->pluck('id');
                    $q->orWhere(fn (Builder $s) => $s->where('subject_type', (new $class)->getMorphClass())->whereIn('subject_id', $ids));
                }
            })
            ->latest('id')
            ->paginate(25, pageName: 'history_page')
            ->withQueryString()
            ->through(fn (Activity $a) => ActivityPresenter::present($a));
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
