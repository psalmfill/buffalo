<?php

namespace App\Http\Controllers\Incidents;

use App\Enums\CasualtyOutcome;
use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use App\Enums\JurisdictionLevel;
use App\Enums\Permission;
use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Http\Presenters\IncidentPresenter;
use App\Http\Presenters\IncidentRows;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\Incident;
use App\Models\IncidentSavedFilter;
use App\Models\IncidentType;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Incidents\IncidentFilters;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Jurisdiction\OfficerNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The incident list and map (Phase 5D). Both use IncidentFilters on the
 * scoped incident query, so a shared filter URL shows each officer only
 * what their own jurisdiction and clearance allow.
 */
class IncidentListController extends Controller
{
    public function index(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('viewAny', Incident::class);
        $user = $this->actor($request);
        $filters = IncidentFilters::from($request->query());

        $page = $filters->apply(Incident::query()->with(['type', 'lga', 'state']), $user)
            ->paginate(25)
            ->withQueryString();

        $page->setCollection(collect(IncidentRows::present($page->items())));

        return Inertia::render('incidents/index', [
            'incidents' => $page,
            ...$this->shared($user, $filters, $options),
        ]);
    }

    public function map(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('viewAny', Incident::class);
        $user = $this->actor($request);

        return Inertia::render('incidents/map', [
            ...$this->shared($user, IncidentFilters::from($request->query())->withDefaultRange(30), $options),
            'map' => MapView::for($user->lga ?? $user->state),
        ]);
    }

    /**
     * The map's points: only what a popup shows (reference, type, category,
     * time, severity, status, position). No titles, descriptions or names.
     */
    public function mapData(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Incident::class);
        $user = $this->actor($request);
        $filters = IncidentFilters::from($request->query())->withDefaultRange(30);

        $base = fn () => $filters->apply(Incident::query(), $user, sort: false);
        $located = $base()->whereNotNull('incidents.location');
        $total = (clone $located)->count();
        $missing = $base()->whereNull('incidents.location')->count();

        $cap = (int) config('buffalo.incidents.map_cap');
        $points = $located
            ->join('incident_types', 'incident_types.id', '=', 'incidents.incident_type_id')
            ->orderByDesc('incidents.occurred_at')
            ->limit($cap)
            ->get([
                'incidents.ulid', 'incidents.reference_no', 'incidents.severity', 'incidents.status', 'incidents.occurred_at',
                'incident_types.name as type_name', 'incident_types.category as category',
                DB::raw('ST_Y(incidents.location::geometry) AS lat'), DB::raw('ST_X(incidents.location::geometry) AS lng'),
            ]);

        return response()->json([
            'points' => $points->map(fn (Incident $i) => [
                'ulid' => $i->ulid,
                'reference_no' => $i->reference_no,
                'type' => (string) $i->getAttribute('type_name'),
                'category' => (string) $i->getAttribute('category'),
                'severity' => $i->severity->value,
                'status' => $i->status->label(),
                'occurred_at' => $i->occurred_at->toIso8601String(),
                'lat' => round((float) $i->getAttribute('lat'), 5),
                'lng' => round((float) $i->getAttribute('lng'), 5),
            ])->values(),
            'total' => $total,
            'capped' => $total > $cap,
            'cap' => $cap,
            'missing_location' => $missing,
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Filter options, the applied filters, saved and default filter sets.
     *
     * @return array<string, mixed>
     */
    private function shared(User $user, IncidentFilters $filters, JurisdictionOptions $options): array
    {
        $states = $options->states($user);

        $values = $filters->toArray();

        return [
            'filters' => $values,
            // Names for the picker filters in a (possibly shared) URL, each
            // through the viewer's own scopes; unknown ones stay unnamed.
            'filterLabels' => [
                'community' => isset($values['community']) ? Community::query()->where('ulid', $values['community'])->value('name') : null,
                'group' => isset($values['group']) && $user->can('viewAny', CriminalGroup::class) ? CriminalGroup::query()->where('ulid', $values['group'])->value('name') : null,
                'officer' => isset($values['officer']) ? OfficerNames::for([DB::table('users')->where('ulid', $values['officer'])->value('id')])->first() : null,
            ],
            'appliedStatuses' => $filters->statuses(),
            'defaultStatuses' => IncidentFilters::DEFAULT_STATUSES,
            'national' => $user->jurisdiction_level === JurisdictionLevel::National,
            'filterOptions' => [
                'types' => IncidentType::query()->orderBy('name')->get()->map(fn (IncidentType $t) => ['value' => $t->ulid, 'label' => $t->name, 'category' => $t->category->value])->values(),
                'categories' => array_map(fn (IncidentCategory $c) => ['value' => $c->value, 'label' => $c->label()], IncidentCategory::cases()),
                'severities' => array_map(fn (Severity $s) => IncidentPresenter::option($s), Severity::cases()),
                'statuses' => array_map(fn (IncidentStatus $s) => IncidentPresenter::option($s), IncidentStatus::cases()),
                'outcomes' => array_map(fn (CasualtyOutcome $o) => IncidentPresenter::option($o), CasualtyOutcome::cases()),
                'states' => $states->count() > 1 ? $states->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name])->values() : [],
                'lgas' => $options->lgas($user)->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => $l->name, 'state' => $states->firstWhere('id', $l->state_id)?->ulid])->values(),
            ],
            'savedFilters' => IncidentSavedFilter::query()->where('user_id', $user->id)->orderBy('name')->get()
                ->map(fn (IncidentSavedFilter $f) => ['ulid' => $f->ulid, 'name' => $f->name, 'filters' => $f->filters])->values(),
            'defaultFilters' => $this->defaults($user),
            'can' => ['create' => $user->can('create', Incident::class)],
        ];
    }

    /**
     * Built-in views, by role.
     *
     * @return list<array{name: string, filters: array<string, mixed>}>
     */
    private function defaults(User $user): array
    {
        $notDraft = array_values(array_diff(array_map(fn (IncidentStatus $s) => $s->value, IncidentStatus::cases()), ['draft']));
        $views = [];

        if ($user->checkPermissionTo(Permission::IncidentsVerify->value)) {
            $views[] = ['name' => 'Awaiting my verification', 'filters' => ['statuses' => ['submitted'], 'sort' => 'occurred_at', 'dir' => 'asc']];
        }
        if ($user->jurisdiction_level === JurisdictionLevel::National) {
            $views[] = ['name' => 'Escalated, unacknowledged', 'filters' => ['escalated' => 'unacknowledged', 'statuses' => $notDraft]];
        }
        $views[] = ['name' => 'My reports', 'filters' => ['mine' => '1', 'statuses' => $notDraft]];
        $views[] = ['name' => 'My drafts', 'filters' => ['statuses' => ['draft'], 'mine' => '1']];

        return $views;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
