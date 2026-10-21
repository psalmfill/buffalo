<?php

namespace App\Support\Reports;

/**
 * A location sketch for printed reports (Phase 11E): an inline SVG drawn on
 * the server from coordinates alone. The LGA's simplified boundary, a point
 * and its radius circle, other points as dots, a scale bar and a north
 * arrow. No map tiles and no URLs of any kind: Gotenberg fetches nothing,
 * by design.
 *
 * Projection: equirectangular around the point (metres east and north),
 * accurate enough at LGA scale. Only numbers are written into the SVG.
 */
final class LocationSketch
{
    private const METRES_PER_DEGREE_LAT = 110_540.0;

    private const METRES_PER_DEGREE_LNG = 111_320.0;

    /** Scale bar lengths to choose from, in metres. */
    private const BARS = [50, 100, 200, 500, 1000, 2000, 5000, 10_000, 20_000, 50_000, 100_000];

    private float $lat0;

    private float $cos;

    /**
     * @param  array<string, mixed>|null  $outline  a GeoJSON Polygon or MultiPolygon (lng, lat)
     * @param  list<array{0: float, 1: float}>  $dots  other points (lat, lng)
     */
    public function __construct(
        private readonly ?array $outline,
        private readonly float $latitude,
        private readonly float $longitude,
        private readonly int $radiusMeters,
        private readonly array $dots = [],
        private readonly int $width = 640,
        private readonly int $height = 400,
    ) {
        $this->lat0 = $latitude;
        $this->cos = cos(deg2rad($latitude));
    }

    public function svg(): string
    {
        $rings = $this->rings();
        $points = [[0.0, 0.0], [-$this->radiusMeters, -$this->radiusMeters], [$this->radiusMeters, $this->radiusMeters]];

        foreach ($rings as $ring) {
            array_push($points, ...$ring);
        }
        foreach ($this->dots as [$lat, $lng]) {
            $points[] = $this->project($lat, $lng);
        }

        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        [$minX, $maxX, $minY, $maxY] = [min($xs), max($xs), min($ys), max($ys)];
        $pad = 24.0;
        $scale = min(($this->width - 2 * $pad) / max($maxX - $minX, 1.0), ($this->height - 2 * $pad) / max($maxY - $minY, 1.0));
        // Centre the drawing in the frame.
        $offsetX = $pad + (($this->width - 2 * $pad) - ($maxX - $minX) * $scale) / 2;
        $offsetY = $pad + (($this->height - 2 * $pad) - ($maxY - $minY) * $scale) / 2;
        $px = fn (float $x): string => self::n($offsetX + ($x - $minX) * $scale);
        $py = fn (float $y): string => self::n($this->height - ($offsetY + ($y - $minY) * $scale));

        $svg = [sprintf('<svg xmlns="http://www.w3.org/2000/svg" class="sketch" viewBox="0 0 %d %d" width="%d" height="%d" role="img" aria-label="Location sketch">', $this->width, $this->height, $this->width, $this->height)];
        $svg[] = sprintf('<rect x="0" y="0" width="%d" height="%d" fill="#ffffff" stroke="#cbd5e1"/>', $this->width, $this->height);

        if ($rings !== []) {
            $d = '';
            foreach ($rings as $ring) {
                $d .= 'M'.implode(' L', array_map(fn (array $p) => $px($p[0]).' '.$py($p[1]), $ring)).' Z ';
            }
            $svg[] = '<path d="'.trim($d).'" fill="#f1f5f9" stroke="#475569" stroke-width="1.2" fill-rule="evenodd"/>';
        }

        $svg[] = sprintf('<circle cx="%s" cy="%s" r="%s" fill="rgba(220,38,38,0.12)" stroke="#dc2626" stroke-width="1.5" stroke-dasharray="5 3"/>', $px(0.0), $py(0.0), self::n(max($this->radiusMeters * $scale, 2.0)));

        foreach ($this->dots as [$lat, $lng]) {
            [$x, $y] = $this->project($lat, $lng);
            $svg[] = sprintf('<circle cx="%s" cy="%s" r="3" fill="#1d4ed8" stroke="#ffffff" stroke-width="1"/>', $px($x), $py($y));
        }

        $svg[] = sprintf('<circle cx="%s" cy="%s" r="5" fill="#dc2626" stroke="#ffffff" stroke-width="1.5"/>', $px(0.0), $py(0.0));
        $svg[] = $this->scaleBar($scale);
        $svg[] = $this->northArrow();
        $svg[] = '</svg>';

        return implode('', $svg);
    }

    /**
     * Boundary rings, projected to metres.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private function rings(): array
    {
        $type = $this->outline['type'] ?? null;
        $coordinates = $this->outline['coordinates'] ?? null;

        if (! is_array($coordinates)) {
            return [];
        }

        $polygons = match ($type) {
            'Polygon' => [$coordinates],
            'MultiPolygon' => $coordinates,
            default => [],
        };
        $rings = [];

        foreach ($polygons as $polygon) {
            foreach ((array) $polygon as $ring) {
                $projected = [];
                foreach ((array) $ring as $position) {
                    if (is_array($position) && isset($position[0], $position[1]) && is_numeric($position[0]) && is_numeric($position[1])) {
                        $projected[] = $this->project((float) $position[1], (float) $position[0]);
                    }
                }
                if (count($projected) >= 3) {
                    $rings[] = $projected;
                }
            }
        }

        return $rings;
    }

    /**
     * @return array{0: float, 1: float} metres east and north of the point
     */
    private function project(float $lat, float $lng): array
    {
        return [
            ($lng - $this->longitude) * self::METRES_PER_DEGREE_LNG * $this->cos,
            ($lat - $this->lat0) * self::METRES_PER_DEGREE_LAT,
        ];
    }

    private function scaleBar(float $scale): string
    {
        $metres = self::BARS[0];
        foreach (self::BARS as $candidate) {
            if ($candidate * $scale <= $this->width * 0.25) {
                $metres = $candidate;
            }
        }

        $length = $metres * $scale;
        $x = 16.0;
        $y = $this->height - 16.0;
        $label = $metres >= 1000 ? ($metres / 1000).' km' : $metres.' m';

        return sprintf(
            '<g class="scale"><rect x="%s" y="%s" width="%s" height="5" fill="#0f172a"/><text x="%s" y="%s" font-size="10" fill="#0f172a">%s</text></g>',
            self::n($x), self::n($y - 5), self::n($length), self::n($x), self::n($y - 9), $label,
        );
    }

    private function northArrow(): string
    {
        $x = $this->width - 28;

        return sprintf(
            '<g class="north"><path d="M%d 34 L%d 14 L%d 34 L%d 29 Z" fill="#0f172a"/><text x="%d" y="48" font-size="11" font-weight="700" text-anchor="middle" fill="#0f172a">N</text></g>',
            $x - 7, $x, $x + 7, $x, $x,
        );
    }

    private static function n(float $value): string
    {
        return number_format($value, 1, '.', '');
    }
}
