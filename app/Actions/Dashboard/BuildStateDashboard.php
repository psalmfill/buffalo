<?php

namespace App\Actions\Dashboard;

use App\Enums\Role;
use App\Enums\SecurityNoteStatus;
use App\Enums\VulnerableCategory;
use App\Models\CommunitySecurityNote;
use App\Models\CriminalGroup;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\OfficerPosting;
use App\Models\PersonOfInterest;
use App\Models\State;
use App\Models\User;
use App\Models\VulnerablePopulationStat;
use App\Support\CommunityInformation\TabData;
use App\Support\Dashboard\AccessFigures;
use App\Support\Dashboard\CoverageFigures;
use App\Support\Dashboard\IncidentFigures;
use App\Support\Dashboard\MapView;
use App\Support\Dashboard\OrganizationFigures;
use App\Support\Dashboard\PlaceholderCards;
use App\Support\Dashboard\ThreatFigures;
use App\Support\Jurisdiction\JurisdictionLabel;
use App\Support\LocalGovernment\ProfileCompleteness;
use Illuminate\Database\Eloquent\Collection;

/**
 * State Director and Deputy State Director dashboard: the state's LGAs with
 * their current LGA Security Chief (from open officer postings) and profile
 * completeness, least complete first (all read through the jurisdiction
 * scope).
 */
final class BuildStateDashboard
{
    public function __construct(private readonly ThreatFigures $figures) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user): array
    {
        /** @var State $state */
        $state = $user->state;
        $label = JurisdictionLabel::state($state);

        $lgas = Lga::query()->active()->where('state_id', $state->id)->orderBy('name')->get();

        $chiefs = OfficerPosting::query()
            ->with('user')
            ->where('role', Role::LgaSecurityChief->value)
            ->whereNull('ended_at')
            ->whereIn('lga_id', $lgas->modelKeys())
            ->orderBy('started_at')
            ->get()
            // The latest posting wins if an LGA ever has two open ones.
            ->keyBy('lga_id');

        $stats = ProfileCompleteness::forLgas($lgas);

        return [
            'state' => [
                'ulid' => $state->ulid,
                'name' => $state->name,
                'label' => $label,
                'zone' => $state->geopoliticalZone->name ?? null,
            ],
            // Least complete profiles first: where work is needed.
            'lgas' => $lgas->map(function (Lga $lga) use ($chiefs, $stats): array {
                $chief = $chiefs->get($lga->id)?->user;
                $lgaStats = $stats->get($lga->id);

                return [
                    'ulid' => $lga->ulid,
                    'name' => $lga->name,
                    'headquarters' => $lga->headquarters,
                    'chief' => $chief === null ? null : ['ulid' => $chief->ulid, 'name' => $chief->name],
                    'completeness' => $lgaStats['completeness'] ?? 0,
                    'last_reviewed_at' => ($lgaStats['last_reviewed_at'] ?? null)?->toIso8601String(),
                ];
            })->sortBy([['completeness', 'asc'], ['name', 'asc']])->values()->all(),
            'securityNotesByLga' => $this->activeNotesByLga($lgas),
            'vulnerableTotals' => $this->vulnerableTotals($state),
            'threats' => [
                'riskLevels' => ThreatFigures::riskLevels(),
                'poisByLga' => $user->can('viewAny', PersonOfInterest::class) ? $this->figures->poisByPlace('lgas') : [],
                'overdueReviews' => $user->can('viewAny', PersonOfInterest::class) ? $this->figures->overdueReviews() : 0,
                'activeGroups' => $user->can('viewAny', CriminalGroup::class) ? $this->figures->activeGroups() : 0,
            ],
            'access' => app(AccessFigures::class)->for($user),
            'organizations' => app(OrganizationFigures::class)->forState($user, $state),
            'coverage' => app(CoverageFigures::class)->forState($user, $state, $lgas),
            'map' => MapView::for($state),
            'incidents' => $user->can('viewAny', Incident::class) ? app(IncidentFigures::class)->forState($user, $state) : null,
            'placeholders' => PlaceholderCards::for($label),
        ];
    }

    /**
     * Active security notes per LGA, most first (jurisdiction and
     * classification scopes apply).
     *
     * @param  Collection<int, Lga>  $lgas
     * @return list<array{ulid: string, name: string, active: int}>
     */
    private function activeNotesByLga(Collection $lgas): array
    {
        /** @var \Illuminate\Support\Collection<int, int> $counts */
        $counts = CommunitySecurityNote::query()
            ->whereIn('lga_id', $lgas->modelKeys())
            ->where('status', SecurityNoteStatus::Active->value)
            ->groupBy('lga_id')
            ->selectRaw('lga_id, count(*) AS total')
            ->pluck('total', 'lga_id')
            ->map(fn (mixed $total): int => (int) $total);

        return array_values($lgas
            ->filter(fn (Lga $lga): bool => $counts->has($lga->id))
            ->map(fn (Lga $lga): array => ['ulid' => $lga->ulid, 'name' => $lga->name, 'active' => $counts->get($lga->id, 0)])
            ->sortBy([['active', 'desc'], ['name', 'asc']])
            ->all());
    }

    /**
     * Vulnerable population totals by category for the state: the sum of
     * each LGA's latest LGA-wide figure (community figures are breakdowns
     * and are not added again).
     *
     * @return list<array{category: string, label: string, adult_male: int, adult_female: int, child_male: int, child_female: int, total: int}>
     */
    private function vulnerableTotals(State $state): array
    {
        $latest = VulnerablePopulationStat::query()->latestPerSeries()
            ->where('vulnerable_population_stats.state_id', $state->id)
            ->whereNull('vulnerable_population_stats.community_id')
            ->get()
            ->groupBy(fn (VulnerablePopulationStat $figure): string => $figure->category->value);

        $rows = [];
        foreach (VulnerableCategory::cases() as $category) {
            $figures = $latest->get($category->value);

            if ($figures === null) {
                continue;
            }

            $rows[] = ['category' => $category->value, 'label' => $category->label(), ...TabData::totals($figures)];
        }

        return $rows;
    }
}
