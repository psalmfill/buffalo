<?php

namespace App\Support\Dashboard;

use App\Enums\SecurityRelevance;
use App\Enums\SiteStatus;
use App\Http\Presenters\OrganizationPresenter;
use App\Models\Lga;
use App\Models\OrganizationSite;
use App\Models\State;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Organization site figures for the dashboards (Phase 4B), always through
 * the site scopes (jurisdiction and classification). Closed sites are left
 * out.
 */
final class OrganizationFigures
{
    /**
     * LGA chief: sites in the LGA by security relevance.
     *
     * @return array<string, int>|null
     */
    public function forLga(User $user, Lga $lga): ?array
    {
        if (! $user->can('viewAny', OrganizationSite::class)) {
            return null;
        }

        $counts = OrganizationSite::query()->where('lga_id', $lga->id)->where('status', '<>', SiteStatus::Closed->value)
            ->selectRaw('security_relevance, count(*) AS total')->groupBy('security_relevance')->toBase()->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->security_relevance => (int) $row->total]);

        $figures = [];

        foreach (SecurityRelevance::cases() as $level) {
            $figures[$level->value] = (int) ($counts[$level->value] ?? 0);
        }

        return $figures;
    }

    /**
     * State roles: critical-infrastructure and high-relevance sites by LGA,
     * and sites with no linked government security.
     *
     * @return array<string, mixed>|null
     */
    public function forState(User $user, State $state): ?array
    {
        if (! $user->can('viewAny', OrganizationSite::class)) {
            return null;
        }

        $base = fn () => OrganizationSite::query()->with(['organization', 'lga', 'state'])
            ->where('organization_sites.state_id', $state->id)
            ->where('organization_sites.status', '<>', SiteStatus::Closed->value);

        $important = $base()
            ->whereIn('organization_sites.security_relevance', [SecurityRelevance::CriticalInfrastructure->value, SecurityRelevance::High->value])
            ->get();

        $unprotected = $base()->whereDoesntHave('formationLinks')
            ->orderByRaw("CASE organization_sites.security_relevance WHEN 'critical_infrastructure' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->limit(50)->get();

        return [
            'importantByLga' => $important->groupBy('lga_id')->map(fn (Collection $sites) => [
                'lga' => ['ulid' => $sites->first()->lga->ulid, 'name' => $sites->first()->lga->name],
                'sites' => $sites->sortByDesc(fn (OrganizationSite $s) => $s->security_relevance->rank())
                    ->map(fn (OrganizationSite $s) => OrganizationPresenter::site($s))->values()->all(),
            ])->sortBy('lga.name')->values()->all(),
            'withoutGovernmentSecurity' => $unprotected->map(fn (OrganizationSite $s) => OrganizationPresenter::site($s))->values()->all(),
        ];
    }

    /**
     * National: high and critical sites by state and sector.
     *
     * @param  Collection<int, State>  $states
     * @return list<array<string, mixed>>|null
     */
    public function national(User $user, Collection $states): ?array
    {
        if (! $user->can('viewAny', OrganizationSite::class)) {
            return null;
        }

        $rows = OrganizationSite::query()
            ->join('organizations', 'organizations.id', '=', 'organization_sites.organization_id')
            ->join('organization_sectors', 'organization_sectors.id', '=', 'organizations.organization_sector_id')
            ->whereIn('organization_sites.state_id', $states->pluck('id')->all())
            ->where('organization_sites.status', '<>', SiteStatus::Closed->value)
            ->whereIn('organization_sites.security_relevance', [SecurityRelevance::CriticalInfrastructure->value, SecurityRelevance::High->value])
            ->selectRaw("organization_sites.state_id, organization_sectors.name AS sector, count(*) FILTER (WHERE organization_sites.security_relevance = 'critical_infrastructure') AS critical, count(*) FILTER (WHERE organization_sites.security_relevance = 'high') AS high")
            ->groupBy('organization_sites.state_id', 'organization_sectors.name')
            ->toBase()->get();

        $names = $states->keyBy('id');

        return array_values($rows->map(fn (object $row) => [
            'state' => ['ulid' => $names[(int) $row->state_id]->ulid, 'name' => $names[(int) $row->state_id]->name],
            'sector' => (string) $row->sector,
            'critical' => (int) $row->critical,
            'high' => (int) $row->high,
        ])->sortBy([['state.name', 'asc'], ['critical', 'desc']])->values()->all());
    }
}
