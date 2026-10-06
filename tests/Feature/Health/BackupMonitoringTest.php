<?php

use App\Notifications\SystemAlert;
use App\Support\Backups\BackupChecks;
use App\Support\Backups\BackupStatus;
use App\Support\Health\SystemAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 8C.1/8C.2: backup alert conditions, read from the status files the
| backup sidecars write, and deduplicated system alerts.
*/

beforeEach(function () {
    Notification::fake();
    $this->world = JurisdictionWorld::build();
    $this->dir = sys_get_temp_dir().'/backup-status-'.uniqid();
    File::ensureDirectoryExists($this->dir.'/pgbackrest');
    File::ensureDirectoryExists($this->dir.'/restic');
    config(['buffalo.backups.status_path' => $this->dir]);
    app()->forgetInstance(BackupStatus::class);
});

afterEach(fn () => File::deleteDirectory($this->dir));

/** Writes a healthy pgBackRest status; $overrides adjust it. */
function writeDatabaseStatus(string $dir, array $overrides = []): void
{
    $o = [
        'backup_at' => now()->subHours(3),
        'wal_at' => now()->subSeconds(20),
        'oldest_ready' => 0,
        'last_failed' => null,
        ...$overrides,
    ];
    $stop = $o['backup_at'] === null ? null : $o['backup_at']->getTimestamp();
    $info = [[
        'name' => 'buffalo', 'status' => ['code' => 0, 'message' => 'ok'],
        'backup' => $stop === null ? [] : [[
            'label' => 'L1F', 'type' => 'full', 'timestamp' => ['start' => $stop - 10, 'stop' => $stop],
            'info' => ['size' => 1000, 'repository' => ['size' => 100]], 'error' => false,
        ]],
    ]];
    file_put_contents("{$dir}/pgbackrest/info.json", json_encode($info));
    file_put_contents("{$dir}/pgbackrest/wal.json", json_encode([
        'at' => $o['wal_at']->toIso8601String(), 'oldest_ready_seconds' => $o['oldest_ready'],
        'stats' => ['last_archived_time' => now()->subMinutes(2)->toIso8601String(), 'last_failed_time' => $o['last_failed']?->toIso8601String(), 'failed_count' => $o['last_failed'] ? 1 : 0],
    ]));
}

function writeTask(string $dir, string $service, string $task, bool $ok, string $message = 'ok'): void
{
    file_put_contents("{$dir}/{$service}/{$task}.json", json_encode(['task' => $task, 'at' => now()->toIso8601String(), 'ok' => $ok, 'message' => $message]));
}

function backupConditions(): array
{
    return array_map(fn ($c) => $c->key, app(BackupChecks::class)->conditions());
}

test('healthy backups raise nothing', function () {
    writeDatabaseStatus($this->dir);

    expect(backupConditions())->toBe([]);
});

test('each database backup problem is a critical condition', function (array $status, ?array $task, string $key) {
    writeDatabaseStatus($this->dir, $status);
    if ($task !== null) {
        writeTask($this->dir, 'pgbackrest', ...$task);
    }

    $conditions = app(BackupChecks::class)->conditions();
    expect(array_map(fn ($c) => $c->key, $conditions))->toContain($key)
        ->and(collect($conditions)->firstWhere('key', $key)->severity)->toBe('critical');
})->with([
    'backup failed' => [[], ['backup-diff', false, 'ERROR: [082]: unable to open'], 'backup.db.backup-diff'],
    'check failed' => [[], ['check', false, 'ERROR: [087]: archive_command'], 'backup.db.check'],
    'verify failed' => [[], ['verify', false, 'ERROR: checksum mismatch'], 'backup.db.verify'],
    'older than 26 hours' => [['backup_at' => now()->subHours(27)], null, 'backup.db.stale'],
    'no backup at all' => [['backup_at' => null], null, 'backup.db.stale'],
    'WAL lag over 15 minutes' => [['oldest_ready' => 16 * 60], null, 'backup.db.wal_lag'],
    'WAL archiving failing' => [['last_failed' => now()->subMinute()], null, 'backup.db.wal_failing'],
    'sidecar silent' => [['wal_at' => now()->subMinutes(6)], null, 'backup.db.sidecar'],
]);

test('a missing status volume is critical', function () {
    config(['buffalo.backups.status_path' => $this->dir.'/missing']);
    app()->forgetInstance(BackupStatus::class);

    expect(backupConditions())->toBe(['backup.status_missing']);
});

test('alerts go to super admins once per condition and once when it clears', function () {
    writeDatabaseStatus($this->dir, ['backup_at' => now()->subHours(30)]);
    $admin = $this->world->users['super_admin'];
    $run = fn () => app(SystemAlerts::class)->sync('backup.', app(BackupChecks::class)->conditions());

    expect($run()['raised'])->toBe(['backup.db.stale']);
    expect($run()['raised'])->toBe([]);
    Notification::assertSentTo($admin, SystemAlert::class, fn (SystemAlert $n) => $n->key === 'backup.db.stale' && $n->level === 'critical' && ! $n->cleared);
    Notification::assertNotSentTo($this->world->users['director_general'], SystemAlert::class);
    expect(Notification::sent($admin, SystemAlert::class))->toHaveCount(1);

    writeDatabaseStatus($this->dir);
    expect($run()['cleared'])->toBe(['backup.db.stale']);
    Notification::assertSentTo($admin, SystemAlert::class, fn (SystemAlert $n) => $n->cleared);
    expect(DB::table('system_alerts')->whereNull('cleared_at')->count())->toBe(0);

    // Raised again after clearing: a new alert.
    writeDatabaseStatus($this->dir, ['backup_at' => now()->subHours(30)]);
    expect($run()['raised'])->toBe(['backup.db.stale']);
});

test('the monitor command runs every registered check', function () {
    writeDatabaseStatus($this->dir, ['oldest_ready' => 3600]);

    $this->artisan('buffalo:health:monitor')->assertSuccessful();

    expect(DB::table('system_alerts')->whereNull('cleared_at')->pluck('key')->all())->toContain('backup.db.wal_lag');
});

test('evidence backup problems are critical; nothing is checked before the service first reports', function () {
    writeDatabaseStatus($this->dir);
    expect(backupConditions())->toBe([]);

    $beat = fn ($at) => file_put_contents("{$this->dir}/restic/heartbeat.json", json_encode(['at' => $at->toIso8601String()]));

    $beat(now());
    writeTask($this->dir, 'restic', 'backup', true);
    expect(backupConditions())->toBe([]);

    writeTask($this->dir, 'restic', 'check-data', false, 'Fatal: pack 1a2b is damaged');
    expect(backupConditions())->toBe(['backup.evidence.check-data']);

    $beat(now()->subMinutes(10));
    expect(backupConditions())->toContain('backup.evidence.sidecar');

    $this->travel(27)->hours();
    $beat(now());
    expect(backupConditions())->toContain('backup.evidence.stale');
});
