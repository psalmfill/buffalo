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
     * Open suggestions the viewer may see, with their visible counts.
     *
     * @param  Builder<BlackspotSuggestion>  $query  scoped
     * @return list<array<string, mixed>>
     */
    public static function rows(Builder $query): array
    {
        $suggestions = $query->withCoordinates()->with(['lga', 'state', 'dominantType'])->get();
        $counts = self::visibleCounts(array_values(array_map('intval', $suggestions->pluck('id')->all())));
        $min = (int) config('buffalo.blackspots.min_points');

        return array_values($suggestions
            ->filter(fn (BlackspotSuggestion $s) => ($counts[$s->id] ?? 0) >= $min)
            ->sortByDesc(fn (BlackspotSuggestion $s) => $counts[$s->id] ?? 0)
            ->map(fn (BlackspotSuggestion $s) => [
                'ulid' => $s->ulid,
                'lga' => ['ulid' => $s->lga->ulid, 'name' => $s->lga->name],
                'state' => ['ulid' => $s->state->ulid, 'name' => $s->state->name],
                'latitude' => $s->latitude,
                'longitude' => $s->longitude,
                'radius_meters' => $s->radius_meters,
                'incident_count' => $counts[$s->id] ?? 0,
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
