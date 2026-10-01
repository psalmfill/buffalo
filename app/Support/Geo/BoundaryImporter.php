<?php

namespace App\Support\Geo;

use App\Support\LocalGovernment\Nigeria;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Writes LGA or state boundaries (Phase 6A.0). Used by the owner-supplied
 * GeoJSON import and by the local-only demo generator.
 *
 * - prepare(): validates one GeoJSON geometry in PostGIS: Polygon or
 *   MultiPolygon only, SRID 4326, repaired with ST_MakeValid when invalid
 *   (and reported), inside Nigeria's bounding box; returned as a
 *   MultiPolygon (hex EWKB).
 * - write(): one transaction: boundaries, centroids (ST_PointOnSurface,
 *   always inside the shape), state boundaries derived from their LGAs
 *   unless states were imported themselves, the source row, and the
 *   simplified display geometries.
 */
final class BoundaryImporter
{
    /** Display tolerances in degrees (ST_SimplifyPreserveTopology). */
    public const SIMPLIFY = ['national' => 0.02, 'state' => 0.005, 'lga' => 0.001];

    /** Slack around Nigeria's bounding box, in degrees. */
    private const BBOX_PAD = 0.05;

    /**
     * @param  array<string, mixed>  $geometry  a GeoJSON geometry object
     * @return array{ewkb: string, repaired: bool, reason: string|null}|string the prepared geometry, or why it is refused
     */
    public function prepare(array $geometry): array|string
    {
        $type = $geometry['type'] ?? null;

        if (! in_array($type, ['Polygon', 'MultiPolygon'], true)) {
            return 'Geometry must be a Polygon or MultiPolygon, not '.(is_string($type) ? $type : 'missing').'.';
        }

        try {
            $json = json_encode($geometry, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'Geometry could not be read.';
        }

        $row = DB::selectOne(<<<'SQL'
            WITH raw AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) AS g),
            checked AS (
                SELECT g, ST_IsValid(g) AS valid, CASE WHEN ST_IsValid(g) THEN NULL ELSE ST_IsValidReason(g) END AS reason FROM raw
            ),
            fixed AS (
                SELECT valid, reason,
                       ST_Multi(CASE WHEN valid THEN g ELSE ST_CollectionExtract(ST_MakeValid(g), 3) END) AS g
                FROM checked
            )
            SELECT valid, reason, ST_IsEmpty(g) AS empty, ST_IsValid(g) AS fixed_valid,
                   ST_Within(g, ST_MakeEnvelope(?, ?, ?, ?, 4326)) AS inside,
                   ST_AsHexEWKB(g) AS ewkb
            FROM fixed
            SQL, [
            $json,
            Nigeria::MIN_LONGITUDE - self::BBOX_PAD, Nigeria::MIN_LATITUDE - self::BBOX_PAD,
            Nigeria::MAX_LONGITUDE + self::BBOX_PAD, Nigeria::MAX_LATITUDE + self::BBOX_PAD,
        ]);

        if ($row === null || $row->empty || ! $row->fixed_valid) {
            return 'Geometry is invalid and could not be repaired'.($row?->reason ? " ({$row->reason})" : '').'.';
        }

        if (! $row->inside) {
            return "Geometry lies (partly) outside Nigeria's bounding box.";
        }

        return ['ewkb' => (string) $row->ewkb, 'repaired' => ! $row->valid, 'reason' => $row->reason];
    }

    /**
     * @param  'lga'|'state'  $level
     * @param  array<int, string>  $geometries  place id => hex EWKB from prepare()
     * @return array{derived_states: int}
     */
    public function write(string $level, array $geometries, string $sourceName, string $attribution, bool $synthetic, ?int $userId): array
    {
        $result = DB::transaction(function () use ($level, $geometries, $sourceName, $attribution, $synthetic, $userId): array {
            $table = $level === 'lga' ? 'lgas' : 'states';

            foreach ($geometries as $id => $ewkb) {
                DB::update(
                    "UPDATE {$table} SET boundary = g::geography, centroid = ST_PointOnSurface(g)::geography, updated_at = now()
                     FROM (SELECT ST_GeomFromEWKB(decode(?, 'hex')) AS g) AS src WHERE {$table}.id = ?",
                    [$ewkb, $id],
                );
            }

            DB::table('boundary_sources')->insert([
                'level' => $level,
                'source_name' => $sourceName,
                'attribution' => $attribution,
                'synthetic' => $synthetic,
                'feature_count' => count($geometries),
                'imported_by' => $userId,
                'imported_at' => now(),
            ]);

            $derived = 0;
            if ($level === 'lga' && ! DB::table('boundary_sources')->where('level', 'state')->exists()) {
                $derived = $this->deriveStates();
            }

            $this->simplify();

            return ['derived_states' => $derived];
        });

        BoundaryAttribution::forget();

        return $result;
    }

    /**
     * State boundaries and centroids from the union of their LGAs, for
     * states without an imported boundary of their own.
     */
    public function deriveStates(): int
    {
        return DB::update(<<<'SQL'
            UPDATE states SET boundary = u.g::geography, centroid = ST_PointOnSurface(u.g)::geography, updated_at = now()
            FROM (
                SELECT state_id, ST_Multi(ST_Union(boundary::geometry)) AS g
                FROM lgas WHERE boundary IS NOT NULL GROUP BY state_id
            ) AS u
            WHERE states.id = u.state_id
            SQL);
    }

    /** Rebuilds the simplified display geometries for every place with a boundary. */
    public function simplify(): void
    {
        DB::table('boundary_simplified')->delete();

        foreach (['lga' => 'lgas', 'state' => 'states'] as $type => $table) {
            foreach (self::SIMPLIFY as $zoom => $tolerance) {
                DB::insert(
                    "INSERT INTO boundary_simplified (place_type, place_id, zoom, geom)
                     SELECT ?, id, ?, ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SimplifyPreserveTopology(boundary::geometry, ?)), 3))
                     FROM {$table} WHERE boundary IS NOT NULL",
                    [$type, $zoom, $tolerance],
                );
            }
        }
    }

    /**
     * Records whose location lies outside their LGA's boundary (checked
     * after an import; nothing is changed). No names: table, ULID, LGA and
     * coordinates only.
     *
     * @return list<array{table: string, ulid: string, lga: string, latitude: float, longitude: float}>
     */
    public function outsideBoundaries(): array
    {
        $rows = [];

        foreach (['communities' => 'location', 'religious_institutions' => 'location', 'agency_formations' => 'location', 'organization_sites' => 'location', 'incidents' => 'location', 'persons_of_interest' => 'last_known_location'] as $table => $column) {
            $found = DB::select(
                "SELECT t.ulid, l.name AS lga, ST_Y(t.{$column}::geometry) AS lat, ST_X(t.{$column}::geometry) AS lng
                 FROM {$table} t JOIN lgas l ON l.id = t.lga_id
                 WHERE t.{$column} IS NOT NULL AND l.boundary IS NOT NULL AND t.deleted_at IS NULL
                   AND NOT ST_Covers(l.boundary, t.{$column})
                 ORDER BY t.id",
            );

            foreach ($found as $r) {
                $rows[] = ['table' => $table, 'ulid' => (string) $r->ulid, 'lga' => (string) $r->lga, 'latitude' => round((float) $r->lat, 6), 'longitude' => round((float) $r->lng, 6)];
            }
        }

        return $rows;
    }
}
