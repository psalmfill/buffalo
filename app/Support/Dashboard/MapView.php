<?php

namespace App\Support\Dashboard;

use App\Models\Lga;
use App\Models\State;
use Illuminate\Support\Facades\DB;

/**
 * Initial map view: centred on the LGA's centroid, else its state's, else
 * the national view (null centre). Centroids are empty until the boundary
 * import.
 *
 * Since Phase 6A the view also carries the place's outline: its simplified
 * display boundary (GeoJSON) at the matching zoom, or null.
 */
final class MapView
{
    /**
     * @return array{center: array{0: float, 1: float}|null, zoom: int, outline: array<string, mixed>|null}
     */
    public static function for(State|Lga|null $place): array
    {
        if ($place === null) {
            return ['center' => null, 'zoom' => 6, 'outline' => null];
        }

        $point = $place->newQuery()
            ->whereKey($place->getKey())
            ->whereNotNull('centroid')
            ->selectRaw('ST_Y(centroid::geometry) AS lat, ST_X(centroid::geometry) AS lng')
            ->toBase()
            ->first();

        if ($point === null) {
            // An LGA without a centroid falls back to its state.
            return $place instanceof Lga ? self::for($place->state) : ['center' => null, 'zoom' => 6, 'outline' => null];
        }

        return [
            'center' => [(float) $point->lat, (float) $point->lng],
            'zoom' => $place instanceof Lga ? 10 : 8,
            'outline' => self::outline($place),
        ];
    }

    /**
     * The simplified display boundary of a place, as a GeoJSON geometry.
     *
     * @return array<string, mixed>|null
     */
    public static function outline(State|Lga $place): ?array
    {
        $type = $place instanceof Lga ? 'lga' : 'state';

        $json = DB::table('boundary_simplified')
            ->where('place_type', $type)
            ->where('place_id', $place->getKey())
            ->where('zoom', $type)
            ->selectRaw('ST_AsGeoJSON(geom, 5) AS json')
            ->value('json');

        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
