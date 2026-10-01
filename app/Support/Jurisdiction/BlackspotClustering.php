<?php

namespace App\Support\Jurisdiction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Finds incident clusters for blackspot suggestions (Phase 6C), over every
 * counted incident of a state whatever any user may see.
 *
 * SANCTIONED SCOPE BYPASS (inside App\Support\Jurisdiction). Runs only in
 * the nightly job and the SD's "Refresh". Only ids, counts and positions
 * leave this class; what each viewer is shown is recomputed through their
 * own scopes (BlackspotSuggestionRows).
 *
 * @phpstan-type Cluster array{incident_ids: list<int>, latitude: float, longitude: float, count: int, radius: float, dominant_type_id: int|null, category: string|null, lga_id: int, first: string, last: string}
 *
 * ST_ClusterDBSCAN runs on Web Mercator coordinates; `eps` is scaled by the
 * state's latitude so that it stays in ground metres. A cluster is kept
 * when it is within `max_radius_meters` and significantly denser than the
 * state's background (Poisson test with a Bonferroni correction,
 * `significance`); otherwise it is re-clustered with half the eps, up to
 * `refinements` times, so that a dense core inside diffuse activity still
 * stands out. Clusters below `min_points` are dropped.
 */
final class BlackspotClustering
{
    /** Counted incidents per square metre in the state (the background). */
    private float $background = 0.0;

    /** Counted incidents considered in the state (for the Bonferroni correction). */
    private int $considered = 0;

    /**
     * @return list<Cluster>
     */
    public function clusters(int $stateId): array
    {
        $found = [];
        $this->background = $this->backgroundDensity($stateId);
        $this->collect($stateId, (float) config('buffalo.blackspots.eps_meters'), null, 0, $found);
        usort($found, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $found;
    }

    /**
     * DBSCAN at $eps over the state's counted incidents (or $ids), keeping
     * clusters within `max_radius_meters` and refining wider ones.
     *
     * @param  list<int>|null  $ids
     * @param  list<Cluster>  $found
     */
    private function collect(int $stateId, float $eps, ?array $ids, int $depth, array &$found): void
    {
        $max = (int) config('buffalo.blackspots.max_radius_meters');
        $minPoints = (int) config('buffalo.blackspots.min_points');

        foreach ($this->run($stateId, $eps, $ids) as $cluster) {
            if ($cluster['count'] < $minPoints) {
                continue;
            }

            if ($cluster['radius'] <= $max && $this->significant($cluster)) {
                $found[] = $cluster;
            } elseif ($depth < (int) config('buffalo.blackspots.refinements')) {
                // Too wide, or not denser than chance as a whole: a denser
                // core may still stand out at a smaller eps.
                $this->collect($stateId, $eps / 2, $cluster['incident_ids'], $depth + 1, $found);
            }
        }
    }

    /**
     * @param  list<int>|null  $ids
     * @return list<Cluster>
     */
    private function run(int $stateId, float $eps, ?array $ids): array
    {
        $minPoints = (int) config('buffalo.blackspots.min_points');
        $perCategory = (bool) config('buffalo.blackspots.per_category');
        $since = CarbonImmutable::now()->subDays((int) config('buffalo.blackspots.days'));
        $latitude = (float) (DB::table('states')->where('id', $stateId)->selectRaw('ST_Y(centroid::geometry) AS lat')->value('lat') ?? 9.0);
        $mercatorEps = $eps / cos(deg2rad($latitude));
        $partition = $perCategory ? 'it.category' : '1';
        $idList = $ids === null ? null : '{'.implode(',', array_map('intval', $ids)).'}';

        $rows = DB::select(<<<SQL
            WITH pts AS (
                SELECT i.id, i.location, i.lga_id, i.incident_type_id, it.category, i.occurred_at,
                       ST_ClusterDBSCAN(ST_Transform(i.location::geometry, 3857), eps => ?, minpoints => ?) OVER (PARTITION BY {$partition}) AS cid,
                       {$partition} AS part
                FROM incidents i
                JOIN incident_types it ON it.id = i.incident_type_id
                WHERE i.state_id = ? AND i.deleted_at IS NULL AND i.location IS NOT NULL
                  AND i.status NOT IN ('draft', 'invalid') AND i.occurred_at >= ?
                  AND (?::text IS NULL OR i.id = ANY(?::bigint[]))
            ), clusters AS (
                SELECT part, cid, array_agg(id ORDER BY id) AS ids, count(*) AS n,
                       ST_Centroid(ST_Collect(location::geometry)) AS c,
                       min(occurred_at) AS first, max(occurred_at) AS last,
                       mode() WITHIN GROUP (ORDER BY incident_type_id) AS dominant,
                       mode() WITHIN GROUP (ORDER BY lga_id) AS lga_mode,
                       CASE WHEN count(DISTINCT category) = 1 THEN min(category) END AS category
                FROM pts WHERE cid IS NOT NULL GROUP BY part, cid
            )
            SELECT array_to_json(ids) AS ids, n, ST_Y(c) AS lat, ST_X(c) AS lng, first, last, dominant, category,
                   (SELECT max(ST_Distance(p.location, c::geography)) FROM pts p WHERE p.id = ANY(ids)) AS radius,
                   COALESCE((SELECT l.id FROM lgas l WHERE l.state_id = ? AND l.boundary IS NOT NULL AND ST_Covers(l.boundary, c::geography) LIMIT 1), lga_mode) AS lga_id
            FROM clusters
            SQL, [$mercatorEps, $minPoints, $stateId, $since, $idList, $idList, $stateId]);

        /** @var list<\stdClass> $rows */
        return array_map(fn (\stdClass $r) => [
            'incident_ids' => array_values(array_map('intval', (array) json_decode((string) $r->ids, true))),
            'latitude' => (float) $r->lat,
            'longitude' => (float) $r->lng,
            'count' => (int) $r->n,
            'radius' => (float) $r->radius,
            'dominant_type_id' => $r->dominant === null ? null : (int) $r->dominant,
            'category' => is_string($r->category) ? $r->category : null,
            'lga_id' => (int) $r->lga_id,
            'first' => (string) $r->first,
            'last' => (string) $r->last,
        ], $rows);
    }

    /**
     * Whether the cluster is denser than chance: under the background
     * density, P(at least `count` incidents in a circle of its radius, at
     * least `min_radius_meters`) is below `significance` / incidents
     * considered (Poisson upper tail, Bonferroni-corrected).
     *
     * @param  array{count: int, radius: float}  $cluster
     */
    private function significant(array $cluster): bool
    {
        $radius = max((float) config('buffalo.blackspots.min_radius_meters'), $cluster['radius']);
        $expected = $this->background * M_PI * $radius ** 2;
        $threshold = (float) config('buffalo.blackspots.significance') / max(1, $this->considered);

        return self::poissonUpperTail($cluster['count'], $expected) < $threshold;
    }

    /** P(X >= $k) for X ~ Poisson($lambda). */
    public static function poissonUpperTail(int $k, float $lambda): float
    {
        if ($lambda <= 0) {
            return $k <= 0 ? 1.0 : 0.0;
        }

        // 1 - P(X <= k-1); the terms shrink fast for the small k used here.
        $cdf = 0.0;
        $term = exp(-$lambda);
        for ($i = 0; $i < $k; $i++) {
            $cdf += $term;
            $term *= $lambda / ($i + 1);
        }

        return max(0.0, 1.0 - $cdf);
    }

    /**
     * Counted incidents in the window per square metre of the state (its
     * boundary when imported, else the incidents' convex hull).
     */
    private function backgroundDensity(int $stateId): float
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT count(*) AS n,
                   COALESCE((SELECT ST_Area(boundary) FROM states WHERE id = ?), ST_Area(ST_ConvexHull(ST_Collect(location::geometry))::geography)) AS area
            FROM incidents
            WHERE state_id = ? AND deleted_at IS NULL AND location IS NOT NULL
              AND status NOT IN ('draft', 'invalid') AND occurred_at >= ?
            SQL, [$stateId, $stateId, CarbonImmutable::now()->subDays((int) config('buffalo.blackspots.days'))]);

        $this->considered = (int) ($row->n ?? 0);

        return $row === null || (float) $row->area <= 0 ? 0.0 : (int) $row->n / (float) $row->area;
    }

    /** Whether an active or monitoring flashpoint's area covers the point. */
    public function coveredByFlashpoint(float $latitude, float $longitude): bool
    {
        return DB::table('flashpoints')
            ->whereNull('deleted_at')
            ->whereIn('status', ['active', 'monitoring'])
            ->whereRaw('ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, radius_meters)', [$longitude, $latitude])
            ->exists();
    }
}
