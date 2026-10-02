<?php

namespace App\Support\Map;

use App\Enums\JurisdictionLevel;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated incident layers for the operational map (Phase 6C), computed
 * in PostGIS from the viewer's scoped, filtered incidents (MapFilters) and
 * cached per scope signature (LayerCache).
 *
 * Density: counts per hexagon of a global Web Mercator hexagon grid
 * (PostGIS's ST_HexagonGrid grid, side `hex_meters` for the zoom bucket;
 * cells computed by HexGrid). Each hexagon
 * belongs to the tile containing its centre, so it is counted once
 * whatever tiles are requested; empty hexagons are omitted. A hexagon is
 * identified by its size and grid indexes (i, j), which the incident list
 * accepts as a filter (`hex`) to list exactly the incidents counted.
 *
 * Choropleths: counts (or rates per 100,000 people) per LGA or state, with
 * the simplified boundaries for the zoom bucket (Phase 6A.0). Without
 * boundaries, centroids (graduated circles); without either, unavailable.
 */
final class IncidentLayers
{
    public function __construct(
        private readonly LayerCache $cache,
        private readonly JurisdictionOptions $options,
    ) {}

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox
     * @return array<string, mixed> GeoJSON FeatureCollection with meta
     */
    public function density(User $user, MapFilters $filters, array $bbox, int $zoom): array
    {
        $bucket = ZoomBucket::forZoom($zoom);
        $tiles = Tile::covering($bbox, $bucket);

        if ($tiles === null) {
            return self::collection([], ['notice' => 'Zoom in to see the density layer.', 'hex_meters' => $bucket->hexMeters]);
        }

        /** @var list<array<string, mixed>> $features */
        $features = [];
        foreach ($tiles as $tile) {
            $features = [...$features, ...(array) $this->cache->remember('density', $user, $filters->values(), $tile->key(), fn () => self::withoutJit(fn () => $this->densityTile($user, $filters, $tile)))];
        }

        $max = (int) config('buffalo.map.max_features');
        $capped = count($features) > $max;

        return self::collection(array_slice($features, 0, $max), [
            'hex_meters' => $bucket->hexMeters,
            'capped' => $capped,
            'max' => $features === [] ? 0 : max(array_map(fn (array $f) => $f['properties']['count'], $features)),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function densityTile(User $user, MapFilters $filters, Tile $tile): array
    {
        $size = $tile->bucket->hexMeters;
        [$w, $s, $e, $n] = $tile->bounds();
        // Points within reach of hexagons centred in the tile.
        $margin = $size * 2 / 111_000;

        $points = $filters->query($user)
            ->whereNotNull('incidents.location')
            ->whereRaw('incidents.location && ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography', [$w - $margin, $s - $margin, $e + $margin, $n + $margin])
            ->selectRaw('ST_Transform(incidents.location::geometry, 3857) AS g')
            ->toBase();

        $counts = DB::query()
            ->fromSub($points, 'p')
            // SQL built from code and integers only: nothing to inject.
            // @phpstan-ignore argument.type
            ->crossJoin(DB::raw('LATERAL '.HexGrid::cellSql('p.g', $size).' AS h'))
            ->groupBy('h.i', 'h.j')
            ->selectRaw('h.i, h.j, count(*) AS n');

        // Each hexagon belongs to the tile containing its centre (half-open
        // bounds), so it is counted once whichever tiles are requested.
        $rows = DB::query()
            ->fromSub($counts, 'c')
            // Integers and %F-formatted floats only: nothing to inject.
            // @phpstan-ignore argument.type
            ->crossJoin(DB::raw('LATERAL (SELECT ST_SetSRID(ST_Hexagon('.(int) $size.', c.i, c.j), 3857) AS hx) AS x'))
            // @phpstan-ignore argument.type
            ->crossJoin(DB::raw('LATERAL (SELECT ST_Transform(ST_MakeEnvelope('.self::floats($w, $s, $e, $n).', 4326), 3857) AS t) AS tile'))
            ->whereRaw('ST_X(ST_Centroid(x.hx)) >= ST_XMin(tile.t) AND ST_X(ST_Centroid(x.hx)) < ST_XMax(tile.t)')
            ->whereRaw('ST_Y(ST_Centroid(x.hx)) >= ST_YMin(tile.t) AND ST_Y(ST_Centroid(x.hx)) < ST_YMax(tile.t)')
            ->selectRaw('c.i, c.j, c.n, ST_AsGeoJSON(ST_Transform(x.hx, 4326), 5) AS geom')
            ->get();

        $features = [];
        foreach ($rows as $row) {
            $features[] = [
                'type' => 'Feature',
                'geometry' => json_decode((string) $row->geom, true),
                'properties' => ['count' => (int) $row->n, 'hex' => "{$size}:{$row->i}:{$row->j}"],
            ];
        }

        return $features;
    }

    /**
     * @param  'lga'|'state'  $level
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox
     * @return array<string, mixed>
     */
    public function choropleth(User $user, MapFilters $filters, string $level, array $bbox, int $zoom, bool $rate): array
    {
        $bucket = ZoomBucket::forZoom($zoom);

        if ($level === 'state' && $user->jurisdiction_level !== JurisdictionLevel::National) {
            return self::collection([], ['mode' => 'unavailable', 'notice' => 'The state layer is for national users.']);
        }

        $placeIds = $level === 'lga'
            ? $this->options->lgaQuery($user)->pluck('lgas.id')->all()
            : $this->options->states($user)->pluck('id')->all();

        // The area is snapped to the tile grid so that panning reuses the cache.
        $tiles = Tile::covering($bbox, $bucket) ?? [];
        $snapped = $tiles === [] ? $bbox : [
            min(array_map(fn (Tile $t) => $t->bounds()[0], $tiles)),
            min(array_map(fn (Tile $t) => $t->bounds()[1], $tiles)),
            max(array_map(fn (Tile $t) => $t->bounds()[2], $tiles)),
            max(array_map(fn (Tile $t) => $t->bounds()[3], $tiles)),
        ];

        return $this->cache->remember("{$level}-choropleth", $user, [...$filters->values(), 'rate' => $rate], implode(',', $snapped).":b{$bucket->index}", fn () => self::withoutJit(function () use ($user, $filters, $level, $placeIds, $snapped, $bucket, $rate): array {
            $table = $level === 'lga' ? 'lgas' : 'states';
            $column = $level === 'lga' ? 'lga_id' : 'state_id';

            $counts = $filters->query($user)
                ->groupBy("incidents.{$column}")
                ->selectRaw("incidents.{$column} AS place, count(*) AS n")
                ->toBase()
                ->pluck('n', 'place');

            $population = $level === 'lga'
                ? 'p.population_estimate'
                : '(SELECT sum(l.population_estimate) FROM lgas l WHERE l.state_id = p.id)';

            $places = DB::table("{$table} AS p")
                ->leftJoin('boundary_simplified AS b', fn ($j) => $j->on('b.place_id', '=', 'p.id')->where('b.place_type', $level)->where('b.zoom', $bucket->boundaryZoom()))
                ->whereIn('p.id', $placeIds)
                ->where(fn ($q) => $q
                    ->whereRaw('b.geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)', $snapped)
                    ->orWhere(fn ($q) => $q->whereNull('b.geom')->whereRaw('p.centroid && ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography', $snapped)))
                ->selectRaw("p.id, p.ulid, p.name, {$population} AS population, ST_AsGeoJSON(b.geom, 5) AS geom, ST_AsGeoJSON(p.centroid::geometry, 5) AS centroid")
                ->get();

            $withBoundary = $places->whereNotNull('geom')->count();
            $mode = match (true) {
                $withBoundary > 0 => 'polygons',
                $places->whereNotNull('centroid')->count() > 0 => 'centroids',
                default => 'unavailable',
            };

            $features = [];
            foreach ($places as $place) {
                $geometry = $mode === 'polygons' ? $place->geom : ($mode === 'centroids' ? $place->centroid : null);

                if ($geometry === null) {
                    continue;
                }

                $count = (int) ($counts[$place->id] ?? 0);
                $population = $place->population === null ? null : (int) $place->population;

                $features[] = [
                    'type' => 'Feature',
                    'geometry' => json_decode((string) $geometry, true),
                    'properties' => [
                        'ulid' => $place->ulid,
                        'name' => $place->name,
                        'count' => $count,
                        'population' => $population,
                        'value' => $rate ? ($population ? round($count * 100_000 / $population, 1) : null) : $count,
                    ],
                ];
            }

            return self::collection($features, [
                'mode' => $mode,
                'measure' => $rate ? 'rate' : 'count',
                'notice' => $mode === 'unavailable' ? ($level === 'lga' ? 'LGA boundaries have not been imported.' : 'State boundaries have not been imported.') : null,
                'max' => $features === [] ? 0 : max(array_map(fn (array $f) => (float) ($f['properties']['value'] ?? 0), $features)),
            ]);
        }));
    }

    /**
     * Runs aggregation queries without PostgreSQL JIT compilation: their
     * large cost estimates trigger JIT, whose compile time (most of a
     * second) far exceeds the query itself.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutJit(Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            DB::statement('SET LOCAL jit = off');

            return $callback();
        });
    }

    /** Floats as SQL literals (%F: locale-independent, nothing to inject). */
    private static function floats(float ...$values): string
    {
        return implode(', ', array_map(fn (float $v) => sprintf('%F', $v), $values));
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function collection(array $features, array $meta = []): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features, 'meta' => $meta];
    }
}
