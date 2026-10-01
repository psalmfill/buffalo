<?php

namespace App\Support\Map;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use App\Support\Incidents\IncidentFilters;
use Illuminate\Database\Eloquent\Builder;

/**
 * The incident filters shared by the incident-based map layers (Phase 6C):
 * date range (default the last `buffalo.map.default_days` days), incident
 * types, category, severities and statuses. Statuses default to every
 * counted status and can never include drafts or invalid reports
 * (statistics rule). Also a geographic area (an LGA or state ULID), used
 * by the dashboards.
 */
final class MapFilters
{
    /** Counted statuses (no drafts, no invalid reports). */
    public const COUNTED = ['submitted', 'returned', 'verified', 'under_investigation', 'resolved', 'closed'];

    private function __construct(private readonly IncidentFilters $filters) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function from(array $input): self
    {
        $values = array_intersect_key($input, array_flip(['from', 'to', 'types', 'category', 'severities', 'statuses', 'state', 'lga']));
        $statuses = $values['statuses'] ?? [];
        $statuses = is_string($statuses) ? explode(',', $statuses) : (array) $statuses;
        $values['statuses'] = array_values(array_intersect($statuses, self::COUNTED)) ?: self::COUNTED;

        $filters = IncidentFilters::from($values);

        if (! isset($filters->values['from']) && ! isset($filters->values['to'])) {
            $filters = $filters->withDefaultRange((int) config('buffalo.map.default_days'));
        }

        return new self($filters);
    }

    /**
     * Canonical values, for cache keys and links to the incident list.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $values = $this->filters->values;
        ksort($values);

        return $values;
    }

    /**
     * The viewer's scoped, filtered incidents (no sorting).
     *
     * @return Builder<Incident>
     */
    public function query(User $user): Builder
    {
        return $this->filters->apply(Incident::query(), $user, sort: false)
            ->whereNotIn('incidents.status', [IncidentStatus::Draft->value, IncidentStatus::Invalid->value]);
    }
}
