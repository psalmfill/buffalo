<?php

namespace App\Http\Controllers\Incidents;

use App\Actions\Incidents\SaveIncident;
use App\Enums\AccessAction;
use App\Enums\IncidentStatus;
use App\Enums\IncidentUpdateType;
use App\Enums\InvalidReason;
use App\Enums\JurisdictionLevel;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\EvidencePresenter;
use App\Http\Presenters\IncidentPresenter;
use App\Http\Presenters\IncidentTimeline;
use App\Http\Presenters\PersonPresenter;
use App\Http\Requests\Incidents\IncidentRequest;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\IncidentAgency;
use App\Models\IncidentCasualty;
use App\Models\IncidentChild;
use App\Models\IncidentCriminalGroup;
use App\Models\IncidentEvidence;
use App\Models\IncidentPerson;
use App\Models\IncidentProperty;
use App\Models\Lga;
use App\Models\RecordAccessRequest;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Evidence\EvidenceFileInspector;
use App\Support\Flashpoints\FlashpointLinks;
use App\Support\Incidents\PossibleDuplicates;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Jurisdiction\OfficerNames;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    /** Detail page tabs (Phase 5D); `duplicates` is for reviewers only. */
    public const TABS = ['overview', 'people', 'response', 'evidence', 'timeline', 'duplicates', 'history'];

    /** Child models whose activity appears in the incident's history. */
    private const CHILDREN = [IncidentCasualty::class, IncidentPerson::class, IncidentCriminalGroup::class, IncidentAgency::class, IncidentProperty::class, IncidentEvidence::class];

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

        // "Save and submit": open the submission dialog on the incident page.
        return redirect()->route('incidents.show', ['incident' => $incident, ...($request->boolean('then_submit') ? ['submit' => 1] : [])])
            ->with('toast', ['type' => 'success', 'message' => 'Draft saved.']);
    }

    public function show(Request $request, Incident $incident, PossibleDuplicates $duplicates): Response
    {
        $this->authorize('view', $incident);
        $user = $this->actor($request);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'overview';

        if ($incident->logsViews()) {
            $incident->recordAccess(context: "tab:{$tab}");
        }
        Inertia::encryptHistory();

        $can = [
            'update' => $user->can('update', $incident),
            'submit' => $user->can('submit', $incident),
            'return' => $user->can('return', $incident),
            'verify' => $user->can('verify', $incident),
            'startInvestigation' => $user->can('startInvestigation', $incident),
            'resolve' => $user->can('resolve', $incident),
            'reopen' => $user->can('reopen', $incident),
            'close' => $user->can('close', $incident),
            'invalidate' => $user->can('invalidate', $incident),
            'escalate' => $user->can('escalate', $incident),
            'acknowledge' => $user->can('acknowledge', $incident),
            'addUpdate' => $user->can('addUpdate', $incident),
        ];
        // Reviewers see possible duplicates of a submitted or verified report.
        $reviewing = $can['verify'] || $can['invalidate'];

        if ($tab === 'duplicates' && ! $reviewing) {
            $tab = 'overview';
        }

        return Inertia::render('incidents/show', [
            // Evidence views are logged only when the Evidence tab shows them.
            ...$this->props($incident, $user, logEvidence: $tab === 'evidence'),
            'tab' => $tab,
            'history' => $tab === 'history' ? $this->history($incident) : null,
            'timeline' => $tab === 'timeline' ? IncidentTimeline::for($incident) : null,
            // Linked and nearby flashpoints the viewer can see (Phase 6A).
            'flashpoints' => $user->can('viewAny', Flashpoint::class) ? FlashpointLinks::forIncident($incident, $user) : null,
            'possibleDuplicates' => $reviewing
                ? $duplicates->for($incident)->map(fn (Incident $d) => IncidentPresenter::duplicate($d))->values()
                : [],
            'workflowOptions' => [
                'invalidReasons' => array_map(fn (InvalidReason $r) => IncidentPresenter::option($r), InvalidReason::cases()),
                'updateTypes' => array_map(fn (IncidentUpdateType $t) => IncidentPresenter::option($t), IncidentUpdateType::cases()),
                'updateLevels' => EvidencePresenter::levels($incident, $user),
            ],
            'channels' => [
                'state' => DB::table('states')->where('id', $incident->state_id)->value('ulid'),
                'national' => $user->jurisdiction_level === JurisdictionLevel::National,
            ],
            'openSubmit' => $request->boolean('submit') && $can['submit'],
            'can' => $can,
        ]);
    }

    /**
     * A print-friendly page (Phase 5D): overview, people, response and
     * timeline, classification marked on every page, "Printed by … on …".
     * Evidence is listed by title only. Everything goes through the same
     * scopes as the page, so nothing above the viewer's clearance is
     * included. Each print is access-logged (`print`).
     */
    public function print(Request $request, Incident $incident): Response
    {
        $this->authorize('view', $incident);
        $user = $this->actor($request);
        $incident->recordAccess(AccessAction::Print, 'print');
        Inertia::encryptHistory();

        $props = $this->props($incident, $user, logEvidence: false);

        return Inertia::render('incidents/print', [
            ...$props,
            'evidence' => array_map(fn (array $item) => [
                'title' => $item['title'],
                'type' => $item['type'],
                'classification' => $item['classification'],
            ], $props['evidence']['items']),
            'timeline' => IncidentTimeline::for($incident),
            'printedBy' => $user->name,
            'printedAt' => now()->toIso8601String(),
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

        // Another LGA must be within reach; after submission only the State
        // Director and above correct it (the reference number stays).
        if ($lga->id !== $incident->lga_id) {
            $this->authorize('createIn', [Incident::class, $lga]);
            $this->authorize('changeLga', $incident);
        }

        $reason = $request->validated('amendment_reason');
        $save->update($incident, $lga, $request->values(), $this->actor($request), is_string($reason) ? trim($reason) : null);

        $thenSubmit = $request->boolean('then_submit') && $this->actor($request)->can('submit', $incident->fresh() ?? $incident);

        return redirect()->route('incidents.show', ['incident' => $incident, ...($thenSubmit ? ['submit' => 1] : [])])
            ->with('toast', ['type' => 'success', 'message' => $incident->status === IncidentStatus::Draft ? 'Draft saved.' : 'Incident updated.']);
    }

    /**
     * The incident and its related rows, each through its own scopes.
     *
     * @return array<string, mixed>
     */
    private function props(Incident $incident, User $user, bool $logEvidence = true): array
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
            'evidence' => $this->evidence($row, $user, $logEvidence),
            'map' => MapView::for($row->lga),
        ];
    }

    /**
     * The evidence items the viewer can see (Phase 5B), each access-logged
     * as listed on the page, and what they may upload.
     *
     * @return array{items: list<array<string, mixed>>, levels: list<array{value: string, label: string}>, extensions: list<string>, max_mb: mixed, can_upload: bool}
     */
    private function evidence(Incident $incident, User $user, bool $log): array
    {
        $items = IncidentEvidence::query()->withCoordinates()->with('media')
            ->where('incident_id', $incident->id)->orderBy('id')->get();

        if ($log) {
            foreach ($items as $item) {
                $item->recordAccess(AccessAction::View, 'incident_page');
            }
        }

        return [
            'items' => EvidencePresenter::list($items, $user),
            'levels' => EvidencePresenter::levels($incident, $user),
            'extensions' => EvidenceFileInspector::EXTENSIONS,
            'max_mb' => config('buffalo.evidence.max_mb'),
            'can_upload' => $user->can('upload', [IncidentEvidence::class, $incident]),
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
            'canChangeLga' => $incident === null || $user->can('changeLga', $incident),
            'canSubmit' => $incident === null ? $user->checkPermissionTo(Permission::IncidentsSubmit->value) : $user->can('submit', $incident),
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
        $page = Activity::query()->with(['causer', 'subject'])
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
            ->withQueryString();

        // Officers outside the viewer's jurisdiction (e.g. the DG acting on a
        // state incident) are named through the sanctioned names-only lookup.
        $userType = (new User)->getMorphClass();
        $names = OfficerNames::for(collect($page->items())->map(fn (Activity $a) => $a->causer_type === $userType ? (int) $a->causer_id : null));

        return $page->through(function (Activity $a) use ($names, $userType): array {
            $entry = ActivityPresenter::present($a);

            if ($entry['causer'] === null && $a->causer_type === $userType && $names->has((int) $a->causer_id)) {
                $entry['causer'] = ['label' => $names->get((int) $a->causer_id), 'ulid' => null, 'type' => 'User'];
            }

            return $entry;
        });
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
