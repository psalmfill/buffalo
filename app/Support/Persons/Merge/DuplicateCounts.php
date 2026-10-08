<?php

namespace App\Support\Persons\Merge;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The nightly duplicate candidate counts per state (Phase 10A.3), kept in
 * the cache: counts only.
 */
final class DuplicateCounts
{
    private const KEY = 'buffalo:persons:duplicate-counts';

    /**
     * @param  array<int, array{certain: int, strong: int, possible: int, total: int}>  $counts
     */
    public static function store(array $counts): void
    {
        Cache::forever(self::KEY, ['at' => now()->toIso8601String(), 'states' => $counts]);
    }

    /**
     * @return array{certain: int, strong: int, possible: int, total: int, at: string}|null
     */
    public static function forState(int $stateId): ?array
    {
        $stored = Cache::get(self::KEY);

        if (! is_array($stored) || ! isset($stored['states']) || ! is_array($stored['states'])) {
            return null;
        }

        $counts = is_array($stored['states'][$stateId] ?? null) ? $stored['states'][$stateId] : [];
        $count = fn (string $key): int => (int) ($counts[$key] ?? 0);

        return [
            'certain' => $count('certain'),
            'strong' => $count('strong'),
            'possible' => $count('possible'),
            'total' => $count('total'),
            'at' => CarbonImmutable::parse((string) $stored['at'])->toIso8601String(),
        ];
    }
}
