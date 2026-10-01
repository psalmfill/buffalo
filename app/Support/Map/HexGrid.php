<?php

namespace App\Support\Map;

/**
 * The hexagon grid of the density layer (Phase 6C): PostGIS's grid
 * (ST_HexagonGrid / ST_Hexagon, flat-topped, side `size`, origin 0,0) in
 * Web Mercator metres.
 *
 * A point's cell is computed arithmetically: cell (i, j) has its centre at
 * x = 1.5·size·i, y = √3·size·(j + ½ when i is odd); the cell containing a
 * point is the one with the nearest centre among the three candidate
 * columns. This is much faster than intersecting each point with a
 * generated grid, assigns a point on an edge to exactly one cell, and is
 * shared by the density layer and the incident list's `hex` filter, so a
 * hexagon's count and its incident list always agree. (A three-row VALUES
 * list, not generate_series, keeps the planner's estimates small; a large
 * estimate triggers JIT compilation costing most of a second.)
 */
final class HexGrid
{
    /**
     * SQL for a LATERAL subquery yielding (i, j) for the 3857 point $point
     * (an SQL expression), e.g. "CROSS JOIN LATERAL (...) AS cell".
     */
    public static function cellSql(string $point, int $size): string
    {
        $s = (float) $size;
        $x = "ST_X({$point})";
        $y = "ST_Y({$point})";
        $odd = '((c.ci % 2) + 2) % 2';

        return <<<SQL
            (SELECT c.ci AS i, c.cj AS j FROM (
                SELECT ci, round({$y} / (sqrt(3) * {$s}) - (((ci % 2) + 2) % 2) * 0.5)::int AS cj
                FROM (SELECT floor({$x} / (1.5 * {$s}))::int + d AS ci FROM (VALUES (-1), (0), (1)) AS o(d)) AS cols
            ) c
            ORDER BY power({$x} - 1.5 * {$s} * c.ci, 2) + power({$y} - sqrt(3) * {$s} * (c.cj + {$odd} * 0.5), 2), c.ci, c.cj
            LIMIT 1)
            SQL;
    }

    /**
     * Parses a hexagon id "size:i:j" (sizes from the zoom buckets only).
     *
     * @return array{size: int, i: int, j: int}|null
     */
    public static function parse(string $id): ?array
    {
        if (preg_match('/^(\d+):(-?\d+):(-?\d+)$/', $id, $m) !== 1) {
            return null;
        }

        $sizes = array_map(fn (array $b) => (int) $b['hex_meters'], (array) config('buffalo.map.zoom_buckets'));

        return in_array((int) $m[1], $sizes, true) ? ['size' => (int) $m[1], 'i' => (int) $m[2], 'j' => (int) $m[3]] : null;
    }
}
