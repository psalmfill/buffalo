<?php

use App\Console\Commands\RetentionReport;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
| Phase 8A.5: retention groundwork. Nothing is deleted.
*/

test('every retention group defaults to keeping records forever', function () {
    $groups = config('buffalo.retention');

    expect(array_keys($groups))->toBe(array_keys(RetentionReport::GROUPS))
        ->and(array_unique(array_values($groups)))->toBe([null]);
});

test('the large append-only tables have BRIN indexes on their time column', function () {
    $brin = DB::table('pg_indexes')->where('indexdef', 'like', '%USING brin%')->pluck('tablename')->sort()->values()->all();

    expect($brin)->toBe(['activity_log', 'login_histories', 'notifications', 'record_access_logs', 'record_match_events', 'search_logs']);
});

test('the retention report lists rows by year and deletes nothing', function () {
    AuditLog::event('retention_test', 'Retention test');
    $before = DB::table('activity_log')->count();

    expect(Artisan::call('buffalo:retention:report'))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('activity_log')->toContain('indefinite')->toContain((string) now()->year)->toContain('Nothing is deleted')
        ->and(DB::table('activity_log')->count())->toBe($before);
});
