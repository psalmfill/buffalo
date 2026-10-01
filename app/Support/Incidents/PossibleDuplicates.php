<?php

namespace App\Support\Incidents;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Possible duplicates of an incident (Phase 5C), among the incidents the
 * current user can see (the query is scoped): same type, occurred within
 * 24 hours either side, and either the same community or locations within
 * 2 km (PostGIS ST_DWithin on geography, in metres). Invalid incidents are
 * left out. An incident with neither a community nor a location cannot be
 * compared by place and has none.
 */
final class PossibleDuplicates
{
    public const RADIUS_METRES = 2000;

    /**
     * @return Collection<int, Incident>
     */
    public function for(Incident $incident): Collection
    {
        $hasLocation = Incident::query()->whereKey($incident->id)->whereNotNull('location')->exists();

        if ($incident->community_id === null && ! $hasLocation) {
            return new Collection;
        }

        return Incident::query()
            ->with('type')
            ->whereKeyNot($incident->id)
            ->where('incident_type_id', $incident->incident_type_id)
            ->where('status', '!=', IncidentStatus::Invalid->value)
            ->whereBetween('occurred_at', [$incident->occurred_at->subDay(), $incident->occurred_at->addDay()])
            ->where(function (Builder $q) use ($incident, $hasLocation): void {
                if ($incident->community_id !== null) {
                    $q->where('community_id', $incident->community_id);
                }

                if ($hasLocation) {
                    $q->orWhereRaw(
                        'ST_DWithin(incidents.location, (SELECT i2.location FROM incidents i2 WHERE i2.id = ?), ?)',
                        [$incident->id, self::RADIUS_METRES],
                    );
                }
            })
            ->orderBy('occurred_at')
            ->limit(20)
            ->get();
    }
}
