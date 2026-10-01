<?php

namespace App\Support\Map;

/**
 * A zoom bucket of the operational map (Phase 6C): the hexagon size and
 * the cache tile size used from one zoom level up to `maxZoom`
 * (config buffalo.map.zoom_buckets).
 */
final class ZoomBucket
{
    private function __construct(
        public readonly int $index,
        public readonly int $maxZoom,
        public readonly int $hexMeters,
        public readonly float $tileDegrees,
    ) {}

    public static function forZoom(int $zoom): self
    {
        /** @var list<array{max_zoom: int, hex_meters: int, tile_degrees: float}> $buckets */
        $buckets = (array) config('buffalo.map.zoom_buckets');

        foreach ($buckets as $i => $bucket) {
            if ($zoom <= (int) $bucket['max_zoom']) {
                return new self($i, (int) $bucket['max_zoom'], (int) $bucket['hex_meters'], (float) $bucket['tile_degrees']);
            }
        }

        $last = count($buckets) - 1;
        $bucket = end($buckets) ?: ['max_zoom' => 99, 'hex_meters' => 500, 'tile_degrees' => 0.1];

        return new self($last, (int) $bucket['max_zoom'], (int) $bucket['hex_meters'], (float) $bucket['tile_degrees']);
    }

    /** The simplified boundary set for this zoom (Phase 6A.0). */
    public function boundaryZoom(): string
    {
        return match (true) {
            $this->maxZoom <= 6 => 'national',
            $this->maxZoom <= 8 => 'state',
            default => 'lga',
        };
    }
}
