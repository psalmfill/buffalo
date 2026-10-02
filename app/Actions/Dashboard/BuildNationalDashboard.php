<?php

namespace App\Actions\Dashboard;

use App\Enums\JurisdictionLevel;
use App\Enums\SecurityNoteCategory;
use App\Enums\SecurityNoteStatus;
use App\Models\CommunitySecurityNote;
use App\Models\CriminalGroup;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\PersonOfInterest;
use App\Models\State;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Dashboard\AccessFigures;
use App\Support\Dashboard\CoverageFigures;
use App\Support\Dashboard\DashboardKpis;
use App\Support\Dashboard\DisseminationFigures;
use App\Support\Dashboard\IncidentFigures;
use App\Support\Dashboard\MapView;
use App\Support\Dashboard\OrganizationFigures;
use App\Support\Dashboard\PlaceholderCards;
use App\Support\Dashboard\ThreatFigures;
use App\Support\Dashboard\ThreatReportFigures;
use App\Support\Jurisdiction\JurisdictionRules;
use App\Support\Jurisdiction\RestrictionType;
use App\Support\LocalGovernment\ProfileCompleteness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Director General and Deputy Director General dashboard. A zoned DDG's
 * counts cover only their zones (JurisdictionRules).
 */
final class BuildNationalDashboard
{
    public function __construct(
        private readonly JurisdictionRules $rules,
        private readonly ThreatFigures $figures,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user, int $days = 30): array
    {
        $restriction = $this->rules->restrictionFor($user, visibleToSuperAdmin: false);

        /** @var Builder<State> $states */
        $states = State::query()->active()
            ->when($restriction->type !== RestrictionType::Everything, fn (Builder $query) => $query->whereIn('id', $restriction->stateIds));

        $label = $user->jurisdictionLabel();

        return [
            'scope' => [
                'label' => $label,
                'zones' => $user->jurisdiction_level === JurisdictionLevel::National
                    ? $user->zones->sortBy('name')->pluck('name')->values()->all()
                    : [],
                'states' => (clone $states)->count(),
                'lgas' => Lga::query()->active()->whereIn('state_id', (clone $states)->select('id'))->count(),
            ],
            'securityNotesByState' => $this->activeNotesByState(),
            'threats' => [
                'riskLevels' => ThreatFigures::riskLevels(),
                'poisByState' => $user->can('viewAny', PersonOfInterest::class) ? $this->figures->poisByPlace('states') : [],
                'groupsByType' => $user->can('viewAny', CriminalGroup::class) ? $this->figures->activeGroupsByType() : [],
            ],
            'completenessByState' => $this->completenessByState((clone $states)->orderBy('name')->get()),
            'access' => app(AccessFigures::class)->for($user),
            'organizations' => app(OrganizationFigures::class)->national($user, (clone $states)->orderBy('name')->get()),
            'coverage' => app(CoverageFigures::class)->national($user, (clone $states)->orderBy('name')->get()),
            'map' => MapView::for(null),
            // Dashboard period and KPI tiles (Phase 7C).
            'period' => ['days' => $days, 'options' => DashboardKpis::PERIODS],
            'kpis' => app(DashboardKpis::class)->for($user, $days),
            'incidents' => $user->can('viewAny', Incident::class) ? app(IncidentFigures::class)->national($user, $days) : null,
            // Threat reports (Phase 6B), through their own scopes.
            'threatReports' => $user->can('viewAny', ThreatReport::class) ? app(ThreatReportFigures::class)->national() : null,
            // Dissemination (Phase 7A).
            'crossBorder' => app(DisseminationFigures::class)->crossBorderGaps($user),
            'placeholders' => PlaceholderCards::for($label, $user),
        ];
    }

    /**
     * Average LGA profile completeness per state, lowest first.
     *
     * @param  Collection<int, State>  $states
     * @return list<array{state: string, lgas: int, average: int}>
     */
    private function completenessByState(Collection $states): array
    {
        $lgas = Lga::query()->active()->whereIn('state_id', $states->modelKeys())->get(['id', 'state_id']);
        $stats = ProfileCompleteness::forLgas($lgas);

        return array_values($states
            ->map(function (State $state) use ($lgas, $stats): array {
                $scores = $lgas->where('state_id', $state->id)->map(fn (Lga $lga): int => $stats[$lga->id]['completeness']);

                return [
                    'state' => $state->name,
                    'lgas' => $scores->count(),
                    'average' => $scores->isEmpty() ? 0 : (int) round($scores->avg()),
                ];
            })
            ->sortBy([['average', 'asc'], ['state', 'asc']])
            ->all());
    }

    /**
     * Active security notes by state and category (jurisdiction and
     * classification scopes apply: a zoned DDG sees their zones only).
     *
     * @return array{categories: list<array{value: string, label: string}>, rows: list<array{state: string, total: int, byCategory: array<string, int>}>}
     */
    private function activeNotesByState(): array
    {
        $counts = CommunitySecurityNote::query()
            ->join('states', 'states.id', '=', 'community_security_notes.state_id')
            ->where('community_security_notes.status', SecurityNoteStatus::Active->value)
            ->groupBy('states.name', 'community_security_notes.category')
            ->selectRaw('states.name AS state, community_security_notes.category AS category, count(*) AS total')
            ->toBase()
            ->get();

        $used = $counts->pluck('category')->unique();
        $categories = array_values(array_map(
            fn (SecurityNoteCategory $category): array => ['value' => $category->value, 'label' => $category->label()],
            array_filter(SecurityNoteCategory::cases(), fn (SecurityNoteCategory $category): bool => $used->contains($category->value)),
        ));

        $rows = $counts->groupBy('state')->map(fn ($group, $state): array => [
            'state' => (string) $state,
            'total' => (int) $group->sum('total'),
            'byCategory' => $group->mapWithKeys(fn (object $row): array => [(string) $row->category => (int) $row->total])->all(),
        ])->sortBy([['total', 'desc'], ['state', 'asc']])->values()->all();

        return ['categories' => $categories, 'rows' => array_values($rows)];
    }
}
