<?php

namespace App\Http\Presenters;

use App\Enums\IncidentStatus;
use App\Models\BlackspotSuggestion;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Suggested blackspots for a viewer (Phase 6C). The stored cluster size is
 * never shown: each suggestion's count is recomputed through the viewer's
 * own incident scopes, and suggestions where the viewer can see fewer
 * than `min_points` member incidents are left out, so a suggestion never
 * reveals incidents the viewer cannot see.
 */
final class BlackspotSuggestionRows
{
    /**
     * Visible member counts per suggestion id.
     *
     * @param  list<int>  $ids
     * @return Collection<int, int>
     */
    public static function visibleCounts(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Incident::query()
            ->whereNotIn('incidents.status', [IncidentStatus::Draft->value, IncidentStatus::Invalid->value])
            ->join('blackspot_suggestion_incidents AS m', 'm.incident_id', '=', 'incidents.id')
            ->whereIn('m.blackspot_suggestion_id', $ids)
            ->groupBy('m.blackspot_suggestion_id')
            ->selectRaw('m.blackspot_suggestion_id AS suggestion, count(*) AS n')
            ->toBase()
            ->pluck('n', 'suggestion')
            ->map(fn ($n) => (int) $n);
    }

    /**
     * The suggestions the viewer may see: each with its count of incidents
     * visible to them (`visible_incidents`), hidden below min_points,
     * busiest first. Shared by the page and the export (Phase 11E).
     *
     * @param  Builder<BlackspotSuggestion>  $query  scoped
     * @return Builder<BlackspotSuggestion>
     */
    public static function visible(Builder $query): Builder
    {
        $counts = Incident::query()
            ->whereNotIn('incidents.status', [IncidentStatus::Draft->value, IncidentStatus::Invalid->value])
            ->join('blackspot_suggestion_incidents AS m', 'm.incident_id', '=', 'incidents.id')
            ->groupBy('m.blackspot_suggestion_id')
            ->selectRaw('m.blackspot_suggestion_id AS suggestion, count(*) AS n')
            ->toBase();

        return $query->withCoordinates()
            ->joinSub($counts, 'visible_counts', 'visible_counts.suggestion', '=', 'blackspot_suggestions.id')
            ->where('visible_counts.n', '>=', (int) config('buffalo.blackspots.min_points'))
            ->addSelect(DB::raw('visible_counts.n AS visible_incidents'))
            ->orderByDesc('visible_counts.n')
            ->orderBy('blackspot_suggestions.id');
    }

    /**
     * Open suggestions the viewer may see, with their visible counts.
     *
     * @param  Builder<BlackspotSuggestion>  $query  scoped
     * @return list<array<string, mixed>>
     */
    public static function rows(Builder $query): array
    {
        return array_values(self::visible($query)->with(['lga', 'state', 'dominantType'])->get()
            ->map(fn (BlackspotSuggestion $s) => [
                'ulid' => $s->ulid,
                'lga' => ['ulid' => $s->lga->ulid, 'name' => $s->lga->name],
                'state' => ['ulid' => $s->state->ulid, 'name' => $s->state->name],
                'latitude' => $s->latitude,
                'longitude' => $s->longitude,
                'radius_meters' => $s->radius_meters,
                'incident_count' => (int) $s->getAttribute('visible_incidents'),
                'dominant_type' => $s->dominantType?->name,
                'category' => $s->category,
                'first_occurred_at' => $s->first_occurred_at->toIso8601String(),
                'last_occurred_at' => $s->last_occurred_at->toIso8601String(),
                'generated_at' => $s->generated_at->toIso8601String(),
                'status' => $s->status,
            ])
            ->all());
    }

    /**
     * The member incidents the viewer can see, as list rows.
     *
     * @return list<array<string, mixed>>
     */
    public static function members(BlackspotSuggestion $suggestion): array
    {
        return IncidentRows::present(Incident::query()
            ->whereNotIn('incidents.status', [IncidentStatus::Draft->value, IncidentStatus::Invalid->value])
            ->whereIn('incidents.id', DB::table('blackspot_suggestion_incidents')->where('blackspot_suggestion_id', $suggestion->id)->select('incident_id'))
            ->with(['type', 'lga', 'state'])
            ->orderByDesc('incidents.occurred_at')
            ->limit(200)
            ->get());
    }
}
