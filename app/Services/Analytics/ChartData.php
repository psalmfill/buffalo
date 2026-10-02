<?php

namespace App\Services\Analytics;

/**
 * The shape every analytics chart takes (Phase 7C): a kind (line, bar,
 * stacked, table, heatmap, stats), labels, series and the aggregate table
 * shown under the chart. The same table is what "Download CSV" exports.
 *
 * @phpstan-type Column array{key: string, label: string, numeric?: bool}
 * @phpstan-type Series array{label: string, data: list<float|int|null>, comparison?: bool}
 * @phpstan-type Chart array{key: string, title: string, kind: string, labels: list<string>, series: list<Series>, columns: list<Column>, rows: list<array<string, mixed>>, note: string|null, link: array<string, mixed>|null}
 */
final class ChartData
{
    /**
     * @param  array<int, string>  $labels
     * @param  array<int, array{label: string, data: array<int, float|int|null>, comparison?: bool}>  $series
     * @param  array<int, array{key: string, label: string, numeric?: bool}>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $link
     * @return array{key: string, title: string, kind: string, labels: list<string>, series: list<array{label: string, data: list<float|int|null>, comparison?: bool}>, columns: list<array{key: string, label: string, numeric?: bool}>, rows: list<array<string, mixed>>, note: string|null, link: array<string, mixed>|null}
     */
    public static function make(string $key, string $title, string $kind, array $labels, array $series, array $columns, array $rows, ?string $note = null, ?array $link = null): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'kind' => $kind,
            'labels' => array_values($labels),
            'series' => array_values(array_map(fn (array $s) => [...$s, 'data' => array_values($s['data'])], $series)),
            'columns' => array_values($columns),
            'rows' => array_values($rows),
            'note' => $note,
            'link' => $link,
        ];
    }

    /** Change in percent, or null when there is nothing to compare with. */
    public static function percent(int|float $current, int|float|null $previous): ?float
    {
        if ($previous === null || (float) $previous === 0.0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Fills bucketed counts into the bucket list (missing buckets are 0).
     *
     * @param  list<array{key: string, label: string}>  $buckets
     * @param  array<string, int|float>  $byKey
     * @return list<int|float>
     */
    public static function fill(array $buckets, array $byKey): array
    {
        return array_map(fn (array $b) => $byKey[$b['key']] ?? 0, $buckets);
    }

    /**
     * Median and 90th percentile (hours) for a list of durations.
     *
     * @return array{median: float|null, p90: float|null}
     */
    public static function hours(?float $median, ?float $p90): array
    {
        return [
            'median' => $median === null ? null : round($median / 3600, 1),
            'p90' => $p90 === null ? null : round($p90 / 3600, 1),
        ];
    }
}
