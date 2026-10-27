<?php

namespace App\Support\LocalGovernment;

use App\Enums\Permission;
use App\Enums\ThreatUrgency;
use App\Models\State;
use App\Models\ThreatReport;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;
use App\Support\Political\Leadership;
use Illuminate\Support\Carbon;

/**
 * The figures of the states list and its export (Phase 11F), per state,
 * through the viewer's own scopes: profile completeness and last review,
 * the State Director, incidents in the last 30 days (the KPI tiles' rule,
 * so drafts and invalid reports are never counted) and active `immediate`
 * threat reports (own records only, as in threat analytics). Figures the
 * viewer may not see are null.
 */
final class StateListFigures
{
    public function __construct(private readonly Leadership $leadership) {}

    /**
     * @param  iterable<State>  $states
     * @return array<int, array{completeness: int, last_reviewed_at: Carbon|null, director: string|null, incidents30: int|null, immediateThreats: int|null}>
     */
    public function for(User $user, iterable $states): array
    {
        $states = collect($states);
        /** @var list<int> $ids */
        $ids = array_values($states->map(fn ($s) => (int) $s->id)->all());

        if ($ids === []) {
            return [];
        }

        $profiles = StateProfileCompleteness::forStates($states);
        $directors = $this->leadership->stateDirectors($ids);

        $incidents = $user->checkPermissionTo(Permission::IncidentsView->value)
            ? AnalyticsFilters::from(['period' => '30d'], $user)->incidents($user)
                ->whereIn('incidents.state_id', $ids)->toBase()
                ->selectRaw('incidents.state_id AS state, count(*) AS total')->groupBy('incidents.state_id')
                ->pluck('total', 'state')->all()
            : null;

        $threats = $user->checkPermissionTo(Permission::ThreatsView->value)
            ? ThreatReport::query()->ownJurisdiction($user)->active()
                ->where('threat_reports.urgency', ThreatUrgency::Immediate->value)
                ->whereIn('threat_reports.state_id', $ids)->toBase()
                ->selectRaw('threat_reports.state_id AS state, count(*) AS total')->groupBy('threat_reports.state_id')
                ->pluck('total', 'state')->all()
            : null;

        $figures = [];
        foreach ($ids as $id) {
            $figures[$id] = [
                'completeness' => $profiles->get($id)['completeness'] ?? 0,
                'last_reviewed_at' => $profiles->get($id)['last_reviewed_at'] ?? null,
                'director' => $directors->get($id),
                'incidents30' => $incidents === null ? null : (int) ($incidents[$id] ?? 0),
                'immediateThreats' => $threats === null ? null : (int) ($threats[$id] ?? 0),
            ];
        }

        return $figures;
    }
}
