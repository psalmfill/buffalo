<?php

namespace App\Support\Dashboard;

use App\Models\Lga;
use App\Models\State;

/**
 * Initial map view for a dashboard: centred on the state's or LGA's
 * centroid when it has one, otherwise the national view (null centre).
 * Centroids are empty until the boundary import.
 */
final class MapView
{
    /**
     * @return array{center: array{0: float, 1: float}|null, zoom: int}
     */
    public static function for(State|Lga|null $place): array
    {
        if ($place === null) {
            return ['center' => null, 'zoom' => 6];
        }

        $point = $place->newQuery()
            ->whereKey($place->getKey())
            ->whereNotNull('centroid')
            ->selectRaw('ST_Y(centroid::geometry) AS lat, ST_X(centroid::geometry) AS lng')
            ->toBase()
            ->first();

        if ($point === null) {
            return ['center' => null, 'zoom' => 6];
        }

        return [
            'center' => [(float) $point->lat, (float) $point->lng],
            'zoom' => $place instanceof Lga ? 10 : 8,
        ];
    }
}
