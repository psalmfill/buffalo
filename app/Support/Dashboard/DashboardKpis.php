<?php

namespace App\Support\Dashboard;

use App\Enums\CasualtyOutcome;
use App\Enums\Permission;
use App\Enums\ThreatStatus;
use App\Enums\ThreatUrgency;
use App\Models\Incident;
use App\Models\ThreatReport;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ChartData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard KPI tiles (Phase 7C) for the chosen period (7, 30 or 90 days)
 * with the change versus the previous period of the same length:
 * incidents, people killed, people abducted, verification within the
 * deadline (%), and active immediate threat reports (now).
 *
 * Built on AnalyticsFilters, so every figure follows the viewer's scopes
 * and the statistics rule, and each tile links to analytics with exactly
 * the same filters.
 */
final class DashboardKpis
{
    public const PERIODS = [7, 30, 90];

    /**
     * @return list<array{key: string, label: string, value: float|int|null, previous: float|int|null, change_pct: float|null, unit: string|null, href: string}>
     */
    public function for(User $user, int $days): array
    {
        $filters = AnalyticsFilters::from(['period' => "{$days}d", 'compare' => 'previous'], $user);
        $link = fn (string $tab) => '/analytics?'.http_build_query(['tab' => $tab, ...$filters->values()]);
        $tiles = [];

        if ($user->checkPermissionTo(Permission::IncidentsView->value)) {
            $current = $filters->incidents($user)->count();
            $previous = $filters->incidents($user, comparison: true)->count();
            $tiles[] = $this->tile('incidents', 'Incidents', $current, $previous, $link('incidents'));

            $tiles[] = $this->tile('killed', 'People killed', $this->people($filters->incidents($user), CasualtyOutcome::Killed), $this->people($filters->incidents($user, comparison: true), CasualtyOutcome::Killed), $link('casualties'));
            $tiles[] = $this->tile('abducted', 'People abducted', $this->people($filters->incidents($user), CasualtyOutcome::Abducted), $this->people($filters->incidents($user, comparison: true), CasualtyOutcome::Abducted), $link('casualties'));
            $tiles[] = $this->tile('verification', 'Verified within deadline', $this->withinDeadline($filters->incidents($user)), $this->withinDeadline($filters->incidents($user, comparison: true)), $link('response'), '%');
        }

        if ($user->checkPermissionTo(Permission::ThreatsView->value)) {
            $active = ThreatReport::query()->ownJurisdiction($user)
                ->where('threat_reports.status', ThreatStatus::Active->value)
                ->where('threat_reports.urgency', ThreatUrgency::Immediate->value)
                ->count();
            $tiles[] = ['key' => 'immediate_threats', 'label' => 'Active immediate threats', 'value' => $active, 'previous' => null, 'change_pct' => null, 'unit' => null, 'href' => $link('threats')];
        }

        return $tiles;
    }

    /**
     * @return array{key: string, label: string, value: float|int|null, previous: float|int|null, change_pct: float|null, unit: string|null, href: string}
     */
    private function tile(string $key, string $label, float|int|null $value, float|int|null $previous, string $href, ?string $unit = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'previous' => $previous,
            'change_pct' => $value === null ? null : ChartData::percent($value, $previous),
            'unit' => $unit,
            'href' => $href,
        ];
    }

    /**
     * @param  Builder<Incident>  $incidents
     */
    private function people(Builder $incidents, CasualtyOutcome $outcome): int
    {
        return (int) DB::table('incident_casualties AS c')
            ->whereNull('c.deleted_at')
            ->where('c.outcome', $outcome->value)
            ->whereIn('c.incident_id', $incidents->select('incidents.id'))
            ->sum(DB::raw('c.adult_male + c.adult_female + c.child_male + c.child_female + c.unknown'));
    }

    /**
     * Of the incidents verified, the share verified within the deadline
     * for their severity (buffalo.incidents.verify_within_minutes).
     *
     * @param  Builder<Incident>  $incidents
     */
    private function withinDeadline(Builder $incidents): ?float
    {
        $minutes = (array) config('buffalo.incidents.verify_within_minutes');
        $row = $incidents->whereNotNull('incidents.verified_at')->toBase()
            ->selectRaw('count(*) AS verified')
            ->selectRaw("count(*) FILTER (WHERE incidents.verified_at - incidents.submitted_at <= (CASE incidents.severity WHEN 'critical' THEN ?::int WHEN 'high' THEN ?::int WHEN 'medium' THEN ?::int ELSE ?::int END) * interval '1 minute') AS within",
                [(int) ($minutes['critical'] ?? 60), (int) ($minutes['high'] ?? 360), (int) ($minutes['medium'] ?? 1440), (int) ($minutes['low'] ?? 2880)])
            ->first();
        $verified = (int) ($row->verified ?? 0);

        return $verified === 0 ? null : round((int) $row->within / $verified * 100, 1);
    }
}
