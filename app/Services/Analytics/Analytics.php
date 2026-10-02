<?php

namespace App\Services\Analytics;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Map\LayerCache;

/**
 * The analytics tabs (Phase 7C) the viewer may open, and their charts.
 *
 * The incident-based areas (incidents, casualties, response) are cached
 * with LayerCache: keyed by the viewer's scope signature, the filters and
 * resolved dates, and the incident data version (bumped on submission and
 * status changes), never shared across signatures. The other areas read
 * small tables and are computed live.
 */
final class Analytics
{
    private const CACHED = ['incidents', 'casualties', 'response'];

    public function __construct(
        private readonly IncidentAnalytics $incidents,
        private readonly CasualtyAnalytics $casualties,
        private readonly ResponseAnalytics $response,
        private readonly ThreatAnalytics $threats,
        private readonly CoverageAnalytics $coverage,
        private readonly AccessAnalytics $access,
        private readonly LayerCache $cache,
    ) {}

    /**
     * @return list<AnalyticsArea>
     */
    public function areas(User $user): array
    {
        if (! $user->checkPermissionTo(Permission::AnalyticsView->value)) {
            return [];
        }

        return array_values(array_filter(
            [$this->incidents, $this->casualties, $this->response, $this->threats, $this->coverage, $this->access],
            fn (AnalyticsArea $area) => $area->allows($user),
        ));
    }

    public function area(User $user, string $key): ?AnalyticsArea
    {
        foreach ($this->areas($user) as $area) {
            if ($area->key() === $key) {
                return $area;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function charts(User $user, AnalyticsArea $area, AnalyticsFilters $filters): array
    {
        if (! in_array($area->key(), self::CACHED, true)) {
            return $area->charts($user, $filters);
        }

        return $this->cache->remember('analytics:'.$area->key(), $user, $filters->cacheKey(), 'analytics', fn () => $area->charts($user, $filters));
    }
}
