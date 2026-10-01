<?php

namespace App\Support\Map;

/**
 * A cell of the fixed tile grid of a zoom bucket (Phase 6C). Requested
 * bounding boxes are snapped to these cells so that cached results are
 * reused while panning. Bounds are in degrees (WGS 84).
 */
final class Tile
{
    public function __construct(
        public readonly ZoomBucket $bucket,
        public readonly int $x,
        public readonly int $y,
    ) {}

    /**
     * The tiles covering a bounding box, or null when there are more than
     * `max_tiles` (the client must zoom in).
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox  west, south, east, north
     * @return list<self>|null
     */
    public static function covering(array $bbox, ZoomBucket $bucket): ?array
    {
        [$w, $s, $e, $n] = $bbox;
        $size = $bucket->tileDegrees;
        $x0 = (int) floor($w / $size);
        $x1 = (int) floor(($e - 1e-9) / $size);
        $y0 = (int) floor($s / $size);
        $y1 = (int) floor(($n - 1e-9) / $size);

        if (($x1 - $x0 + 1) * ($y1 - $y0 + 1) > (int) config('buffalo.map.max_tiles')) {
            return null;
        }

        $tiles = [];
        for ($x = $x0; $x <= $x1; $x++) {
            for ($y = $y0; $y <= $y1; $y++) {
                $tiles[] = new self($bucket, $x, $y);
            }
        }

        return $tiles;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float} west, south, east, north
     */
    public function bounds(): array
    {
        $size = $this->bucket->tileDegrees;

        return [$this->x * $size, $this->y * $size, ($this->x + 1) * $size, ($this->y + 1) * $size];
    }

    public function key(): string
    {
        return "b{$this->bucket->index}:{$this->x}:{$this->y}";
    }
}
