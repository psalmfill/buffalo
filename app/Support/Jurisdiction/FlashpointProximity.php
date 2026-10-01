<?php

namespace App\Support\Jurisdiction;

use App\Enums\FlashpointStatus;
use App\Models\Flashpoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Matches incidents to flashpoint areas whatever the acting user may see
 * (Phase 6A).
 *
 * SANCTIONED SCOPE BYPASS (inside App\Support\Jurisdiction). When an
 * incident is submitted, the flashpoints whose area contains it must be
 * found even if the submitter cannot see them, so their owners can be
 * told. Only ids and counts leave this class, plus flashpoint models used
 * solely to address notifications; what each recipient is shown is
 * checked per recipient (FlashpointAlerts). Nothing here reaches a page.
 */
final class FlashpointProximity
{
    /**
     * Active and monitoring flashpoints whose area contains the incident's
     * location (ST_DWithin on the GIST index).
     *
     * @return list<int>
     */
    public function watchedContaining(int $incidentId): array
    {
        return array_values(array_map('intval', DB::table('flashpoints AS f')
            ->join('incidents AS i', fn ($join) => $join->whereRaw(Flashpoint::containsSql('f', 'i.location')))
            ->where('i.id', $incidentId)
            ->whereNotNull('i.location')
            ->whereNull('f.deleted_at')
            ->whereIn('f.status', [FlashpointStatus::Active->value, FlashpointStatus::Monitoring->value])
            ->orderBy('f.id')
            ->pluck('f.id')
            ->all()));
    }

    /**
     * Flashpoints by id, for addressing notifications only.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Flashpoint>
     */
    public function load(array $ids): Collection
    {
        return Flashpoint::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereKey($ids)
            ->with(['lga', 'threatType'])
            ->get();
    }

    /**
     * Incidents (not drafts or invalid, not deleted) since $since that are
     * linked to the flashpoint or lie inside its area. A count only: it is
     * never shown, it decides whether to send the reassessment notice.
     */
    public function recentIncidentCount(int $flashpointId, CarbonImmutable $since): int
    {
        return (int) DB::table('incidents AS i')
            ->join('flashpoints AS f', fn ($join) => $join->where('f.id', '=', $flashpointId))
            ->whereNull('i.deleted_at')
            ->whereNotIn('i.status', ['draft', 'invalid'])
            ->where('i.occurred_at', '>=', $since)
            ->where(fn ($q) => $q
                ->whereRaw(Flashpoint::containsSql('f', 'i.location'))
                ->orWhereExists(fn ($link) => $link->from('flashpoint_incidents AS l')
                    ->whereColumn('l.incident_id', 'i.id')
                    ->whereColumn('l.flashpoint_id', 'f.id')
                    ->whereNull('l.deleted_at')))
            ->count();
    }
}
