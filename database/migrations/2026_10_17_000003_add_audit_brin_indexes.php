<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BRIN indexes on the time column of the large append-only tables (Phase
 * 8A.5). Rows arrive in time order, so a BRIN index is tiny (a few pages
 * per million rows) and serves the range scans of retention reports and
 * any future retention policy. The existing B-tree indexes stay for the
 * application's own lookups.
 */
return new class extends Migration
{
    private const TABLES = [
        'activity_log' => 'created_at',
        'login_histories' => 'created_at',
        'record_access_logs' => 'created_at',
        'record_match_events' => 'created_at',
        'search_logs' => 'created_at',
        'notifications' => 'created_at',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $column) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$table}_{$column}_brin ON {$table} USING brin ({$column}) WITH (pages_per_range = 32)");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $column) {
            DB::statement("DROP INDEX IF EXISTS {$table}_{$column}_brin");
        }
    }
};
