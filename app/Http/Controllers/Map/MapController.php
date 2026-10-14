<?php

namespace App\Http\Controllers\Map;

use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use App\Enums\JurisdictionLevel;
use App\Enums\Permission;
use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Http\Presenters\IncidentPresenter;
use App\Models\AgencyFormation;
use App\Models\BlackspotSuggestion;
use App\Models\Community;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\OrganizationSite;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\StateLens;
use App\Support\Map\MapFilters;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operational map (Phase 6C): one full-screen map with every layer the
 * user may see. Layers load from /map/layers/... as the view changes; the
 * layer and filter state lives in the URL so views can be shared (each
 * viewer still sees only their own scopes).
 */
class MapController extends Controller
{
    public function show(Request $request, JurisdictionOptions $options, StateLens $lens): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->checkPermissionTo(Permission::MapView->value), 403);

        $national = $user->jurisdiction_level === JurisdictionLevel::National;
        $incidents = $user->can('viewAny', Incident::class);
        $lgaIds = array_values(array_map('intval', $options->lgaQuery($user)->pluck('lgas.id')->all()));
        $tz = (string) config('app.timezone');
        $lensFilter = $lens->filter($request);

        return Inertia::render('map/index', [
            // Opens on the state lens, if set (Phase 11A.2); the URL's own
            // view parameters still win in the browser.
            'view' => MapView::forFilter($lensFilter['state'] ?? null, $lensFilter['zone'] ?? null) ?? MapView::for($user->lga ?? $user->state),
            'national' => $national,
            'layers' => array_keys(array_filter([
                'density' => $incidents,
                'lga_choropleth' => $incidents,
                'state_choropleth' => $incidents && $national,
                'incidents' => $incidents,
                'flashpoints' => $user->can('viewAny', Flashpoint::class),
                'threats' => $user->can('viewAny', ThreatReport::class),
                'suggestions' => $user->can('viewAny', BlackspotSuggestion::class),
                'formations' => $user->can('viewAny', AgencyFormation::class),
                'sites' => $user->can('viewAny', OrganizationSite::class),
                'communities' => $user->can('viewAny', Community::class),
            ])),
            // Whether choropleths can be drawn for the user's area (Phase 6A boundaries).
            'boundaries' => [
                'lga' => $this->boundaryMode('lgas', $lgaIds),
                'state' => $this->boundaryMode('states', array_values(array_map('intval', $options->states($user)->pluck('id')->all()))),
            ],
            'filterOptions' => [
                'types' => IncidentType::query()->orderBy('name')->get()->map(fn (IncidentType $t) => ['value' => $t->ulid, 'label' => $t->name, 'category' => $t->category->value])->values(),
                'categories' => array_map(fn (IncidentCategory $c) => ['value' => $c->value, 'label' => $c->label()], IncidentCategory::cases()),
                'severities' => array_map(fn (Severity $s) => IncidentPresenter::option($s), Severity::cases()),
                'statuses' => array_map(fn (string $s) => IncidentPresenter::option(IncidentStatus::from($s)), MapFilters::COUNTED),
            ],
            'defaults' => [
                'from' => CarbonImmutable::now($tz)->subDays((int) config('buffalo.map.default_days'))->toDateString(),
                'to' => CarbonImmutable::now($tz)->toDateString(),
            ],
        ]);
    }

    /**
     * @param  'lgas'|'states'  $table
     * @param  list<int>  $ids
     */
    private function boundaryMode(string $table, array $ids): string
    {
        $row = DB::table($table)->whereIn('id', $ids)
            ->selectRaw('bool_or(boundary IS NOT NULL) AS b, bool_or(centroid IS NOT NULL) AS c')->first();

        return match (true) {
            (bool) ($row->b ?? false) => 'polygons',
            (bool) ($row->c ?? false) => 'centroids',
            default => 'none',
        };
    }
}
