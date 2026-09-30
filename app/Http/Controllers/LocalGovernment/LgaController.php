<?php

namespace App\Http\Controllers\LocalGovernment;

use App\Actions\LocalGovernment\MarkLgaProfileReviewed;
use App\Actions\LocalGovernment\UpdateLgaProfile;
use App\Enums\JurisdictionLevel;
use App\Enums\Permission;
use App\Enums\SecurityRelevance;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AgencyFormationPresenter;
use App\Http\Presenters\CommunityInformationPresenter;
use App\Http\Presenters\CommunityPresenter;
use App\Http\Presenters\LgaPresenter;
use App\Http\Presenters\OrganizationPresenter;
use App\Http\Requests\LocalGovernment\LgaProfileRequest;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use App\Models\Lga;
use App\Models\LgaProfile;
use App\Models\OrganizationSite;
use App\Models\PoliticalOffice;
use App\Models\ReligiousInstitution;
use App\Models\SenatorialDistrict;
use App\Models\User;
use App\Models\VulnerablePopulationStat;
use App\Support\CommunityInformation\TabData;
use App\Support\Dashboard\MapView;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\LocalGovernment\ProfileCompleteness;
use App\Support\Political\Leadership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * LGA pages: the list of LGAs in the user's jurisdiction and each LGA's
 * overview, profile and communities. {lga} is bound within the user's
 * jurisdiction (routes/local-government.php): others are 404.
 */
class LgaController extends Controller
{
    /**
     * Tabs of an LGA page. Phase 2C and later phases add theirs here.
     */
    public const TABS = ['overview', 'profile', 'communities', 'leadership', 'religious_institutions', 'vulnerable_population', 'security_notes', 'security_presence', 'organizations'];

    /** Map markers per LGA page (plain markers, no clustering). */
    private const MARKER_LIMIT = 1000;

    public function index(Request $request, JurisdictionOptions $options): Response|RedirectResponse
    {
        $this->authorize('viewAny', Lga::class);
        $user = $this->actor($request);

        // An LGA chief has exactly one LGA: go straight to it.
        if ($user->jurisdiction_level === JurisdictionLevel::Lga && $user->lga !== null) {
            return redirect()->route('lgas.show', $user->lga);
        }

        $states = $options->states($user);

        $filters = $request->validate([
            'state' => ['nullable', 'string', Rule::in($states->pluck('ulid'))],
            'district' => ['nullable', 'string', 'size:26'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $state = isset($filters['state']) ? $states->firstWhere('ulid', $filters['state']) : null;

        $lgas = $options->lgaQuery($user)
            ->active()
            ->with(['state', 'senatorialDistrict'])
            ->when($state, fn (Builder $query) => $query->where('lgas.state_id', $state?->id))
            ->when($filters['district'] ?? null, fn (Builder $query, string $district) => $query->whereHas('senatorialDistrict', fn (Builder $q) => $q->where('ulid', $district)))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('lgas.name', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->join('states', 'states.id', '=', 'lgas.state_id')
            ->orderBy('states.name')
            ->orderBy('lgas.name')
            ->select('lgas.*')
            ->paginate(25)
            ->withQueryString();

        $stats = ProfileCompleteness::forLgas($lgas->items());

        return Inertia::render('lgas/index', [
            'lgas' => $lgas->through(fn (Lga $lga) => LgaPresenter::row($lga, $stats->get($lga->id, ['completeness' => 0, 'communities' => 0, 'last_reviewed_at' => null]))),
            'filters' => [
                'state' => $filters['state'] ?? '',
                'district' => $filters['district'] ?? '',
                'search' => $filters['search'] ?? '',
            ],
            'states' => $states->count() > 1 ? $states->map(fn ($s) => ['ulid' => $s->ulid, 'name' => $s->name])->values() : [],
            'districts' => SenatorialDistrict::query()
                ->whereIn('state_id', $state !== null ? [$state->id] : $states->modelKeys())
                ->orderBy('name')
                ->get(['ulid', 'name'])
                ->map(fn (SenatorialDistrict $district) => ['ulid' => $district->ulid, 'name' => $district->name]),
        ]);
    }

    public function show(Request $request, Lga $lga, TabData $data, Leadership $leadership): Response
    {
        $this->authorize('view', $lga);
        $user = $this->actor($request);

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';
        $lga->loadMissing(['state', 'senatorialDistrict']);

        $profile = LgaProfile::query()->with('reviewer')->firstWhere('lga_id', $lga->id);
        $communityCount = Community::query()->where('lga_id', $lga->id)->count();

        return Inertia::render('lgas/show', [
            'lga' => LgaPresenter::facts($lga),
            'tab' => $tab,
            'tabs' => self::TABS,
            'completeness' => LgaPresenter::completeness($profile, $communityCount),
            'communityCount' => $communityCount,
            'lastReviewed' => [
                'at' => $profile?->last_reviewed_at?->toIso8601String(),
                'by' => $profile?->reviewer?->name,
            ],
            'can' => [
                'updateProfile' => $user->can('updateProfile', $lga),
                'addCommunity' => $user->can('addCommunity', $lga),
                'importCommunities' => $user->can('importCommunities', $lga),
                'addReligiousInstitution' => $user->can('addReligiousInstitution', $lga),
                'addVulnerableFigures' => $user->can('addVulnerableFigures', $lga),
                'addSecurityNote' => $user->can('addSecurityNote', $lga),
                'viewReligiousInstitutions' => $user->can('viewAny', ReligiousInstitution::class),
                'viewVulnerableFigures' => $user->can('viewAny', VulnerablePopulationStat::class),
                'viewSecurityNotes' => $user->can('viewAny', CommunitySecurityNote::class),
                'viewLeadership' => $user->can('viewAny', PoliticalOffice::class),
                'viewFormations' => $user->can('viewAny', AgencyFormation::class),
                'addFormation' => $user->can('create', AgencyFormation::class),
                'viewOrganizations' => $user->can('viewAny', OrganizationSite::class),
                // For showing row actions; each action is authorised on the record.
                'deleteReligiousInstitutions' => $user->checkPermissionTo(Permission::ReligiousInstitutionsDelete->value),
                'deleteVulnerableFigures' => $user->checkPermissionTo(Permission::VulnerableStatsDelete->value),
            ],
            'options' => CommunityInformationPresenter::options(),
            'overview' => $tab === 'overview' ? ['chief' => $leadership->chief($lga)] : null,
            'leadership' => $tab === 'leadership' && $user->can('viewAny', PoliticalOffice::class) ? $leadership->forLga($lga) : null,
            'profile' => $tab === 'profile' ? [
                ...LgaPresenter::profile($profile),
                'terrainOptions' => LgaPresenter::terrainOptions(),
            ] : null,
            'communities' => $tab === 'communities' ? [...$this->communities($request, $lga), 'institutionMarkers' => $user->can('viewAny', ReligiousInstitution::class) ? $data->institutionMarkers($lga) : []] : null,
            'religiousInstitutions' => $tab === 'religious_institutions' && $user->can('viewAny', ReligiousInstitution::class)
                ? [...$data->religiousInstitutions($lga, null, $request), 'communities' => $this->communityOptions($lga), 'map' => MapView::for($lga)]
                : null,
            'vulnerablePopulation' => $tab === 'vulnerable_population' && $user->can('viewAny', VulnerablePopulationStat::class)
                ? $data->vulnerablePopulation($lga, null, $request)
                : null,
            'securityNotes' => $tab === 'security_notes' && $user->can('viewAny', CommunitySecurityNote::class)
                ? $data->securityNotes($lga, null, $request)
                : null,
            // Organization sites in the LGA (Phase 4B), through their own scopes.
            'organizations' => $tab === 'organizations' && $user->can('viewAny', OrganizationSite::class)
                ? $this->organizationSites($request, $lga)
                : null,
            // Formations in the LGA (Phase 4A), through their own scopes.
            'securityPresence' => $tab === 'security_presence' && $user->can('viewAny', AgencyFormation::class)
                ? [
                    'formations' => AgencyFormation::query()->withCoordinates()->with(['agency', 'lga', 'state'])
                        ->where('agency_formations.lga_id', $lga->id)
                        ->orderBy('agency_formations.name')
                        ->get()
                        ->map(fn (AgencyFormation $f) => AgencyFormationPresenter::row($f))
                        ->values(),
                    'map' => MapView::for($lga),
                ]
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationSites(Request $request, Lga $lga): array
    {
        $relevance = SecurityRelevance::tryFrom((string) $request->query('relevance'));

        return [
            'sites' => OrganizationSite::query()->withCoordinates()->with(['organization', 'lga', 'state'])
                ->where('organization_sites.lga_id', $lga->id)
                ->when($relevance, fn ($q) => $q->where('organization_sites.security_relevance', $relevance?->value))
                ->orderByRaw("CASE organization_sites.security_relevance WHEN 'critical_infrastructure' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
                ->orderBy('organization_sites.name')
                ->get()
                ->map(fn (OrganizationSite $s) => OrganizationPresenter::site($s))
                ->values(),
            'relevance' => $relevance === null ? '' : $relevance->value,
            'relevanceOptions' => array_map(fn (SecurityRelevance $r) => ['value' => $r->value, 'label' => $r->label()], SecurityRelevance::cases()),
            'map' => MapView::for($lga),
        ];
    }

    public function updateProfile(LgaProfileRequest $request, Lga $lga, UpdateLgaProfile $update): RedirectResponse
    {
        $this->authorize('updateProfile', $lga);

        $update($lga, $request->profile(), $request->basics(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Profile saved.']);
    }

    public function markReviewed(Request $request, Lga $lga, MarkLgaProfileReviewed $review): RedirectResponse
    {
        $this->authorize('updateProfile', $lga);

        $review($lga, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Profile marked as reviewed.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function communities(Request $request, Lga $lga): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', Rule::in(array_column(CommunityPresenter::typeOptions(), 'value'))],
        ]);

        $base = fn (): Builder => Community::query()
            ->where('communities.lga_id', $lga->id)
            ->with(['lga', 'state', 'ward'])
            ->withCoordinates();

        $list = $base()
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('communities.type', $type))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->search($search), fn (Builder $query) => $query->orderBy('communities.name'))
            ->paginate(25, pageName: 'page')
            ->withQueryString();

        return [
            'list' => $list->through(fn (Community $community) => CommunityPresenter::row($community)),
            'filters' => ['search' => $filters['search'] ?? '', 'type' => $filters['type'] ?? ''],
            'types' => CommunityPresenter::typeOptions(),
            'markers' => $base()->whereNotNull('communities.location')->orderBy('communities.name')->limit(self::MARKER_LIMIT)->get()
                ->map(fn (Community $community) => CommunityPresenter::marker($community))
                ->filter()
                ->values(),
            'map' => MapView::for($lga),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function communityOptions(Lga $lga): array
    {
        return array_values(Community::query()->where('lga_id', $lga->id)->orderBy('name')->get(['ulid', 'name'])
            ->map(fn (Community $community): array => ['value' => $community->ulid, 'label' => $community->name])
            ->all());
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
