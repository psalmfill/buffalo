<?php

namespace App\Support\Incidents;

use App\Enums\CasualtyOutcome;
use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The incident filters (Phase 5D), shared by the list, the map, saved
 * filters and shared URLs. They narrow an already scoped query: whatever
 * the filters say, each viewer only ever sees incidents their own
 * jurisdiction and clearance allow. Other users' drafts never appear.
 *
 * Values are ULIDs and enum values, never numeric ids, so a filter URL can
 * be shared between officers.
 */
final class IncidentFilters
{
    /** The default status filter: open work, no drafts, closed or invalid. */
    public const DEFAULT_STATUSES = ['submitted', 'returned', 'verified', 'under_investigation', 'resolved'];

    public const SORTS = ['occurred_at', 'reference', 'severity', 'status', 'type'];

    /** Keys that may appear in a filter URL or a saved filter. */
    public const KEYS = ['q', 'types', 'category', 'severities', 'statuses', 'escalated', 'from', 'to', 'state', 'lga', 'community', 'has_casualties', 'outcome', 'overdue', 'officer', 'group', 'mine', 'no_location', 'sort', 'dir'];

    /**
     * @param  array<string, mixed>  $values  validated
     */
    private function __construct(public readonly array $values) {}

    /**
     * Validates raw filter values (query string or a saved filter); unknown
     * keys are dropped and invalid values are ignored rather than failing
     * the page, so an old shared URL still opens.
     *
     * @param  array<string, mixed>  $input
     */
    public static function from(array $input): self
    {
        $input = array_intersect_key($input, array_flip(self::KEYS));

        foreach (['types', 'severities', 'statuses'] as $list) {
            if (isset($input[$list]) && is_string($input[$list])) {
                $input[$list] = array_values(array_filter(explode(',', $input[$list])));
            }
        }

        $rules = [
            'q' => ['string', 'max:200'],
            'types' => ['array', 'max:50'],
            'types.*' => ['string', 'max:30'],
            'category' => [Rule::enum(IncidentCategory::class)],
            'severities' => ['array'],
            'severities.*' => [Rule::enum(Severity::class)],
            'statuses' => ['array'],
            'statuses.*' => [Rule::enum(IncidentStatus::class)],
            'escalated' => [Rule::in(['yes', 'no', 'unacknowledged'])],
            'from' => ['date_format:Y-m-d'],
            'to' => ['date_format:Y-m-d'],
            'state' => ['string', 'max:30'],
            'lga' => ['string', 'max:30'],
            'community' => ['string', 'max:30'],
            'has_casualties' => [Rule::in(['1'])],
            'outcome' => [Rule::enum(CasualtyOutcome::class)],
            'overdue' => [Rule::in(['1'])],
            'officer' => ['string', 'max:30'],
            'group' => ['string', 'max:30'],
            'mine' => [Rule::in(['1'])],
            'no_location' => [Rule::in(['1'])],
            'sort' => [Rule::in(self::SORTS)],
            'dir' => [Rule::in(['asc', 'desc'])],
        ];

        $valid = [];
        foreach ($rules as $key => $rule) {
            if (! array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null || $input[$key] === []) {
                continue;
            }

            $checks = array_filter($rules, fn (string $k) => $k === $key || str_starts_with($k, "{$key}."), ARRAY_FILTER_USE_KEY);
            $value = is_bool($input[$key]) ? ($input[$key] ? '1' : null) : $input[$key];

            if ($value !== null && ! Validator::make([$key => $value], $checks)->fails()) {
                $valid[$key] = is_string($value) ? trim($value) : array_values(array_unique($value));
            }
        }

        return new self($valid);
    }

    /**
     * The status filter actually applied (the default when none is set).
     *
     * @return list<string>
     */
    public function statuses(): array
    {
        /** @var list<string> */
        return $this->values['statuses'] ?? self::DEFAULT_STATUSES;
    }

    /** A date range for the map: the last 30 days unless set. */
    public function withDefaultRange(int $days): self
    {
        if (isset($this->values['from']) || isset($this->values['to'])) {
            return $this;
        }

        return new self([...$this->values, 'from' => CarbonImmutable::now((string) config('app.timezone'))->subDays($days)->toDateString()]);
    }

    /**
     * Narrows a scoped incident query.
     *
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    public function apply(Builder $query, User $user, bool $sort = true): Builder
    {
        $v = $this->values;
        $tz = (string) config('app.timezone');

        // Other users' drafts never appear, whatever the filters.
        $query->where(fn (Builder $q) => $q->where('incidents.status', '!=', IncidentStatus::Draft->value)->orWhere('incidents.created_by', $user->id));
        $query->whereIn('incidents.status', $this->statuses());

        if (isset($v['types'])) {
            $query->whereIn('incidents.incident_type_id', DB::table('incident_types')->whereIn('ulid', $v['types'])->select('id'));
        }
        if (isset($v['category'])) {
            $query->whereIn('incidents.incident_type_id', DB::table('incident_types')->where('category', $v['category'])->select('id'));
        }
        if (isset($v['severities'])) {
            $query->whereIn('incidents.severity', $v['severities']);
        }
        if (isset($v['escalated'])) {
            match ($v['escalated']) {
                'yes' => $query->whereNotNull('incidents.escalated_at'),
                'no' => $query->whereNull('incidents.escalated_at'),
                default => $query->whereNotNull('incidents.escalated_at')->whereNull('incidents.escalation_acknowledged_at'),
            };
        }
        if (isset($v['from'])) {
            $query->where('incidents.occurred_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $v['from'], $tz)?->startOfDay());
        }
        if (isset($v['to'])) {
            $query->where('incidents.occurred_at', '<', CarbonImmutable::createFromFormat('Y-m-d', $v['to'], $tz)?->addDay()->startOfDay());
        }
        if (isset($v['state'])) {
            $query->whereIn('incidents.state_id', DB::table('states')->where('ulid', $v['state'])->select('id'));
        }
        if (isset($v['lga'])) {
            $query->whereIn('incidents.lga_id', DB::table('lgas')->where('ulid', $v['lga'])->select('id'));
        }
        if (isset($v['community'])) {
            $query->whereIn('incidents.community_id', DB::table('communities')->where('ulid', $v['community'])->select('id'));
        }
        if (isset($v['has_casualties']) || isset($v['outcome'])) {
            $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('incident_casualties')
                ->whereColumn('incident_casualties.incident_id', 'incidents.id')
                ->whereNull('incident_casualties.deleted_at')
                ->when(isset($v['outcome']), fn ($s) => $s->where('incident_casualties.outcome', $v['outcome'])));
        }
        if (isset($v['overdue'])) {
            $this->overdue($query);
        }
        if (isset($v['officer'])) {
            $query->whereIn('incidents.reporting_officer_id', DB::table('users')->where('ulid', $v['officer'])->select('id'));
        }
        if (isset($v['group'])) {
            $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('incident_criminal_groups')
                ->whereColumn('incident_criminal_groups.incident_id', 'incidents.id')
                ->whereNull('incident_criminal_groups.deleted_at')
                ->whereIn('incident_criminal_groups.criminal_group_id', DB::table('criminal_groups')->where('ulid', $v['group'])->select('id')));
        }
        if (isset($v['mine'])) {
            $query->where(fn (Builder $q) => $q->where('incidents.reporting_officer_id', $user->id)->orWhere('incidents.created_by', $user->id));
        }
        if (isset($v['no_location'])) {
            $query->whereNull('incidents.location');
        }

        $ranked = false;
        if (isset($v['q'])) {
            $ranked = $this->search($query, $v['q']);
        }

        if ($sort) {
            $this->sort($query, $ranked);
        }

        return $query;
    }

    /**
     * Submitted incidents past their verification deadline (5C config).
     *
     * @param  Builder<Incident>  $query
     */
    public static function overdue(Builder $query): void
    {
        /** @var array<string, int|string> $minutes */
        $minutes = (array) config('buffalo.incidents.verify_within_minutes');

        $query->where('incidents.status', IncidentStatus::Submitted->value)
            ->whereRaw(
                // The application's clock (as the 5C reminders use), not the database's.
                "incidents.submitted_at + (CASE incidents.severity WHEN 'critical' THEN ?::int WHEN 'high' THEN ?::int WHEN 'medium' THEN ?::int ELSE ?::int END) * interval '1 minute' < ?::timestamptz",
                [(int) ($minutes['critical'] ?? 60), (int) ($minutes['high'] ?? 360), (int) ($minutes['medium'] ?? 1440), (int) ($minutes['low'] ?? 2880), CarbonImmutable::now()->toIso8601String()],
            );
    }

    /**
     * A reference number (BUF/…) is matched exactly, ignoring case; anything
     * else is a ranked full-text search. Returns whether results are ranked.
     *
     * @param  Builder<Incident>  $query
     */
    private function search(Builder $query, string $term): bool
    {
        if (preg_match('#^BUF/#i', $term) === 1) {
            $query->whereRaw('lower(incidents.reference_no) = lower(?)', [$term]);

            return false;
        }

        $query->whereRaw("incidents.search_vector @@ websearch_to_tsquery('english', ?)", [$term]);

        if (! isset($this->values['sort'])) {
            $query->orderByRaw("ts_rank(incidents.search_vector, websearch_to_tsquery('english', ?)) DESC", [$term]);
        }

        return true;
    }

    /**
     * @param  Builder<Incident>  $query
     */
    private function sort(Builder $query, bool $ranked): void
    {
        $dir = ($this->values['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        match ($this->values['sort'] ?? ($ranked ? null : 'occurred_at')) {
            'reference' => $query->orderByRaw("incidents.reference_no {$dir} NULLS LAST"),
            'severity' => $query->orderByRaw("CASE incidents.severity WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END {$dir}"),
            'status' => $query->orderBy('incidents.status', $dir),
            'type' => $query->orderBy(DB::table('incident_types')->select('name')->whereColumn('incident_types.id', 'incidents.incident_type_id'), $dir),
            'occurred_at' => $query->orderBy('incidents.occurred_at', $dir),
            default => null,
        };

        $query->orderBy('incidents.id', $dir);
    }

    /**
     * For a URL query string or a saved filter.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
