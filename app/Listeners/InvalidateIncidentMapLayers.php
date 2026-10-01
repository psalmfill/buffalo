<?php

namespace App\Listeners;

use App\Events\Incidents\IncidentStatusChanged;
use App\Events\Incidents\IncidentSubmitted;
use App\Support\Map\LayerCache;
use Illuminate\Support\Facades\DB;

/**
 * Drops cached incident map layers (Phase 6C) for the incident's state and
 * nationally when an incident is submitted or changes status, by bumping
 * the cache versions (LayerCache).
 */
final class InvalidateIncidentMapLayers
{
    public function handle(IncidentSubmitted|IncidentStatusChanged $event): void
    {
        $stateId = DB::table('states')->where('ulid', $event->stateUlid)->value('id');

        if (is_int($stateId)) {
            LayerCache::bump($stateId);
        }
    }
}
