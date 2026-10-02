<?php

namespace App\Services\Analytics;

use App\Enums\JurisdictionLevel;
use App\Enums\Permission;
use App\Models\State;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Cross-jurisdiction oversight (Phase 7C): national users holding
 * `access_requests.oversee` only. Requests by requesting and owning state,
 * approval rate, time to decision, escalations, active grants; hits shown
 * and suppressed by the searcher's state; officers reaching the daily hit
 * limits (buffalo.cross_jurisdiction soft/hard).
 *
 * DDGs with zones see only rows where the requesting or owning state is in
 * their zones (JurisdictionOptions::states). Counts only: no person data.
 */
final class AccessAnalytics implements AnalyticsArea
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'access';
    }

    public function label(): string
    {
        return 'Cross-jurisdiction';
    }

    public function allows(User $user): bool
    {
        return $user->jurisdiction_level === JurisdictionLevel::National
            && $user->checkPermissionTo(Permission::AccessRequestsOversee->value);
    }

    public function charts(User $user, AnalyticsFilters $filters): array
    {
        return [
            $this->summary($user, $filters),
            $this->byStates($user, $filters),
            $this->hits($user, $filters),
            $this->limits($user, $filters),
        ];
    }

    /** @return list<int> */
    private function stateIds(User $user, AnalyticsFilters $filters): array
    {
        return array_values(array_map('intval', $this->options->states($user)
            ->when($filters->zone !== null, fn ($c) => $c->where('geopolitical_zone_id', $filters->zone?->id))
            ->when($filters->state !== null, fn ($c) => $c->where('id', $filters->state?->id))
            ->pluck('id')->all()));
    }

    private function requests(User $user, AnalyticsFilters $filters): QueryBuilder
    {
        $states = $this->stateIds($user, $filters);

        return DB::table('record_access_requests AS r')
            ->where('r.created_at', '>=', $filters->start)->where('r.created_at', '<', $filters->end)
            ->where(fn (QueryBuilder $q) => $q->whereIn('r.requester_state_id', $states)->orWhereIn('r.owning_state_id', $states));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user, AnalyticsFilters $filters): array
    {
        $row = $this->requests($user, $filters)
            ->selectRaw('count(*) AS total')
            ->selectRaw("count(*) FILTER (WHERE r.status = 'approved') AS approved")
            ->selectRaw("count(*) FILTER (WHERE r.status = 'rejected') AS rejected")
            ->selectRaw('count(*) FILTER (WHERE r.escalated_at IS NOT NULL) AS escalated')
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM r.decided_at - r.created_at)) FILTER (WHERE r.decided_at IS NOT NULL) AS median')
            ->selectRaw('percentile_cont(0.9) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM r.decided_at - r.created_at)) FILTER (WHERE r.decided_at IS NOT NULL) AS p90')
            ->first();
        $states = $this->stateIds($user, $filters);
        $active = DB::table('record_access_grants AS g')
            ->join('record_access_requests AS r', 'r.id', '=', 'g.request_id')
            ->whereNull('g.revoked_at')->where('g.starts_at', '<=', now())->where('g.expires_at', '>', now())
            ->where(fn (QueryBuilder $q) => $q->whereIn('r.requester_state_id', $states)->orWhereIn('r.owning_state_id', $states))
            ->count();
        $approved = (int) ($row->approved ?? 0);
        $rejected = (int) ($row->rejected ?? 0);
        $hours = ChartData::hours($row?->median === null ? null : (float) $row->median, $row?->p90 === null ? null : (float) $row->p90);
        $rows = [
            ['label' => 'Requests', 'value' => (int) ($row->total ?? 0)],
            ['label' => 'Approved', 'value' => $approved],
            ['label' => 'Rejected', 'value' => $rejected],
            ['label' => 'Approval rate (%)', 'value' => ThreatAnalytics::rate($approved, $approved + $rejected)],
            ['label' => 'Escalated', 'value' => (int) ($row->escalated ?? 0)],
            ['label' => 'Median time to decision (h)', 'value' => $hours['median']],
            ['label' => '90th percentile time to decision (h)', 'value' => $hours['p90']],
            ['label' => 'Grants active now', 'value' => $active],
        ];

        return ChartData::make('access_summary', 'Access requests', 'stats', array_column($rows, 'label'),
            [['label' => 'Value', 'data' => array_column($rows, 'value')]],
            [['key' => 'label', 'label' => 'Measure'], ['key' => 'value', 'label' => 'Value', 'numeric' => true]], $rows,
            'Approval rate: approved ÷ (approved + rejected).');
    }

    /**
     * @return array<string, mixed>
     */
    public function byStates(User $user, AnalyticsFilters $filters): array
    {
        $names = State::query()->pluck('name', 'id');
        $rows = $this->requests($user, $filters)
            ->selectRaw('r.requester_state_id AS requester, r.owning_state_id AS owner, count(*) AS n')
            ->selectRaw("count(*) FILTER (WHERE r.status = 'approved') AS approved")
            ->selectRaw("count(*) FILTER (WHERE r.status IN ('pending', 'escalated')) AS open")
            ->groupBy('requester', 'owner')->orderByDesc('n')->get()
            ->map(fn ($r) => [
                'label' => ($names[$r->requester] ?? '—').' → '.($names[$r->owner] ?? '—'),
                'requester' => $names[$r->requester] ?? null,
                'owner' => $names[$r->owner] ?? null,
                'requests' => (int) $r->n,
                'approved' => (int) $r->approved,
                'open' => (int) $r->open,
            ])->values()->all();

        return ChartData::make('access_by_states', 'Requests by requesting and owning state', 'table', array_column($rows, 'label'),
            [['label' => 'Requests', 'data' => array_column($rows, 'requests')]], [
                ['key' => 'requester', 'label' => 'Requesting state'],
                ['key' => 'owner', 'label' => 'Owning state'],
                ['key' => 'requests', 'label' => 'Requests', 'numeric' => true],
                ['key' => 'approved', 'label' => 'Approved', 'numeric' => true],
                ['key' => 'open', 'label' => 'Open', 'numeric' => true],
            ], $rows);
    }

    private function events(User $user, AnalyticsFilters $filters): QueryBuilder
    {
        return DB::table('record_match_events AS e')
            ->join('users AS u', 'u.id', '=', 'e.user_id')
            ->where('e.created_at', '>=', $filters->start)->where('e.created_at', '<', $filters->end)
            ->whereIn('u.state_id', $this->stateIds($user, $filters));
    }

    /**
     * @return array<string, mixed>
     */
    public function hits(User $user, AnalyticsFilters $filters): array
    {
        $names = State::query()->pluck('name', 'id');
        $rows = $this->events($user, $filters)
            ->selectRaw('u.state_id AS state, count(*) FILTER (WHERE NOT e.suppressed) AS shown, count(*) FILTER (WHERE e.suppressed) AS suppressed')
            ->groupBy('state')->get()
            ->map(fn ($r) => ['label' => $names[$r->state] ?? '—', 'shown' => (int) $r->shown, 'suppressed' => (int) $r->suppressed])
            ->sortBy('label')->values()->all();

        return ChartData::make('hits', 'Hits shown and suppressed, by searcher\'s state', 'stacked', array_column($rows, 'label'), [
            ['label' => 'Shown', 'data' => array_column($rows, 'shown')],
            ['label' => 'Suppressed', 'data' => array_column($rows, 'suppressed')],
        ], [
            ['key' => 'label', 'label' => 'State'],
            ['key' => 'shown', 'label' => 'Shown', 'numeric' => true],
            ['key' => 'suppressed', 'label' => 'Suppressed', 'numeric' => true],
        ], $rows);
    }

    /**
     * Officers whose hits shown in a day reached the soft or hard limit.
     *
     * @return array<string, mixed>
     */
    public function limits(User $user, AnalyticsFilters $filters): array
    {
        $soft = (int) config('buffalo.cross_jurisdiction.soft_daily_limit');
        $hard = (int) config('buffalo.cross_jurisdiction.hard_daily_limit');
        $days = DB::query()->fromSub($this->events($user, $filters)->where('e.suppressed', false)
            ->selectRaw('e.user_id, u.name AS officer, u.state_id, e.created_at::date AS day, count(*) AS n')
            ->groupBy('e.user_id', 'u.name', 'u.state_id', 'day'), 'd')
            ->where('d.n', '>=', $soft)
            ->selectRaw('d.officer, d.state_id, count(*) AS soft_days, count(*) FILTER (WHERE d.n >= ?) AS hard_days, max(d.n) AS most', [$hard])
            ->groupBy('d.user_id', 'd.officer', 'd.state_id')
            ->orderByDesc('soft_days')->get();
        $names = State::query()->pluck('name', 'id');
        $rows = $days->map(fn ($r) => [
            'label' => (string) $r->officer,
            'state' => $names[$r->state_id] ?? '—',
            'soft_days' => (int) $r->soft_days,
            'hard_days' => (int) $r->hard_days,
            'most' => (int) $r->most,
        ])->values()->all();

        return ChartData::make('limits', 'Officers reaching the daily hit limits', 'table', array_column($rows, 'label'),
            [['label' => 'Days at the soft limit', 'data' => array_column($rows, 'soft_days')]], [
                ['key' => 'label', 'label' => 'Officer'],
                ['key' => 'state', 'label' => 'State'],
                ['key' => 'soft_days', 'label' => "Days with {$soft}+ hits", 'numeric' => true],
                ['key' => 'hard_days', 'label' => "Days at the limit ({$hard})", 'numeric' => true],
                ['key' => 'most', 'label' => 'Most in a day', 'numeric' => true],
            ], $rows);
    }
}
