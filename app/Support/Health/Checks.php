<?php

namespace App\Support\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\WaitTimeCalculator;

/**
 * The health checks other than backups (Phase 8C.4). Thresholds live in
 * config/buffalo.php `health`.
 */
final class Checks
{
    /**
     * Free and total bytes of a path; replaceable in tests.
     *
     * @var (\Closure(string): (array{0: float|false, 1: float|false}))|null
     */
    public static ?\Closure $measure = null;

    /**
     * Disks in use, by name => path (volumes mounted in the app container).
     *
     * @return array<string, string>
     */
    public static function disks(): array
    {
        /** @var array<string, string> $disks */
        $disks = (array) config('buffalo.health.disks');
        foreach (array_filter(explode(',', (string) config('buffalo.health.extra_disks'))) as $pair) {
            [$name, $path] = array_pad(explode('=', $pair, 2), 2, '');
            if ($name !== '' && $path !== '') {
                $disks[trim($name)] = trim($path);
            }
        }

        return $disks;
    }

    /**
     * Free and total bytes of each disk; null when unreadable.
     *
     * @return array<string, array{path: string, free: int, total: int, used_percent: float}|null>
     */
    public static function diskUsage(): array
    {
        $usage = [];
        foreach (self::disks() as $name => $path) {
            [$free, $total] = self::$measure !== null ? (self::$measure)($path)
                : (is_dir($path) ? [@disk_free_space($path), @disk_total_space($path)] : [false, false]);
            $usage[$name] = $free === false || $total === false || $total <= 0 ? null : [
                'path' => $path, 'free' => (int) $free, 'total' => (int) $total,
                'used_percent' => round(100 * (1 - $free / $total), 1),
            ];
        }

        return $usage;
    }

    /**
     * Longest current wait per queue, in seconds (Horizon).
     *
     * @return array<string, int>
     */
    public static function queueWaits(): array
    {
        try {
            return array_map('intval', app(WaitTimeCalculator::class)->calculate());
        } catch (\Throwable) {
            return [];
        }
    }

    public static function failedJobs(int $hours = 24): int
    {
        return (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subHours($hours))->count();
    }

    /** The newest scheduled command run, or null if none was recorded. */
    public static function lastScheduledRun(): ?CarbonImmutable
    {
        $at = DB::table('health_status')->where('key', 'like', 'schedule.%')->max('recorded_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at);
    }
}
