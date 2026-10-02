<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Row counts and sizes by year for every table group with a retention
 * setting (Phase 8A.5). Read-only: nothing is deleted. Sizes per year are
 * the table's total size (with indexes and TOAST) shared out by row count.
 */
class RetentionReport extends Command
{
    protected $signature = 'buffalo:retention:report';

    protected $description = 'Show row counts and sizes by year per table, with the configured retention (read-only)';

    /** group => [table => time column] */
    public const GROUPS = [
        'audit' => ['activity_log' => 'created_at', 'record_access_logs' => 'created_at', 'record_match_events' => 'created_at', 'audit_anchors' => 'created_at'],
        'login_history' => ['login_histories' => 'created_at'],
        'search_logs' => ['search_logs' => 'created_at'],
        'analytics_exports' => ['analytics_exports' => 'created_at'],
        'notifications' => ['notifications' => 'created_at'],
        'report_runs' => ['report_runs' => 'created_at'],
        'incidents' => ['incidents' => 'created_at', 'incident_status_histories' => 'changed_at', 'incident_updates' => 'created_at', 'incident_evidence' => 'created_at'],
        'persons' => ['persons' => 'created_at'],
        'cleared_persons_of_interest' => ['persons_of_interest' => 'created_at'],
        'threats_and_flashpoints' => ['threat_reports' => 'created_at', 'flashpoints' => 'created_at', 'flashpoint_assessments' => 'assessed_at'],
    ];

    public function handle(): int
    {
        $rows = [];

        foreach (self::GROUPS as $group => $tables) {
            $days = config("buffalo.retention.{$group}");
            $retention = $days === null ? 'indefinite' : "{$days} days";

            foreach ($tables as $table => $column) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $size = (int) DB::selectOne('SELECT pg_total_relation_size(?::regclass) AS bytes', [$table])->bytes;
                $years = DB::table($table)->selectRaw("extract(year FROM {$column})::int AS year, count(*) AS rows")
                    ->groupByRaw('1')->orderByRaw('1')->get();
                $total = max(1, (int) $years->sum('rows'));

                if ($years->isEmpty()) {
                    $rows[] = [$group, $table, $retention, '—', 0, self::bytes($size)];
                }

                foreach ($years as $year) {
                    $rows[] = [$group, $table, $retention, $year->year ?? 'undated', number_format((int) $year->rows), self::bytes((int) round($size * (int) $year->rows / $total))];
                }
            }
        }

        $this->table(['Group', 'Table', 'Retention', 'Year', 'Rows', 'Size (est.)'], $rows);
        $this->components->info('Nothing is deleted: retention periods are not set yet (docs/security/retention.md). Expired report files are the only automatic deletion.');

        return self::SUCCESS;
    }

    private static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => round($bytes / 1073741824, 1).' GB',
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            default => round($bytes / 1024).' KB',
        };
    }
}
