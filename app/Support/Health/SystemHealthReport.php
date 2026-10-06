<?php

namespace App\Support\Health;

use App\Support\Backups\BackupStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Everything System health shows (Phase 8C.4), as plain arrays: services,
 * queues, scheduler, backups, storage, audit chains, key fingerprints, the
 * TLS certificate and open alerts. No intelligence content: counts, times,
 * sizes, results and fingerprints only. Also the body of /health/detailed.
 */
final class SystemHealthReport
{
    public function __construct(
        private readonly ServiceProbe $services,
        private readonly BackupStatus $backups,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        return [
            'generated_at' => self::time(CarbonImmutable::now()),
            'services' => $this->services(),
            'queues' => $this->queues(),
            'scheduler' => $this->scheduler(),
            'backups' => $this->backups(),
            'storage' => $this->storage(),
            'audit' => $this->audit(),
            'keys' => DB::table('key_fingerprints')->where('status', 'active')->orderBy('key_name')
                ->get(['key_name', 'fingerprint', 'activated_at'])
                ->map(fn (object $k) => ['name' => (string) $k->key_name, 'fingerprint' => (string) $k->fingerprint, 'activated_at' => self::time($k->activated_at)])->all(),
            'certificate' => ($expiry = StackChecks::certificateExpiry()) === null
                ? ['configured' => (string) config('buffalo.health.tls_certificate') !== '', 'expires_at' => null, 'days_left' => null]
                : ['configured' => true, 'expires_at' => self::time($expiry), 'days_left' => (int) floor(CarbonImmutable::now()->diffInDays($expiry, false))],
            'alerts' => DB::table('system_alerts')->whereNull('cleared_at')->orderByRaw("severity = 'critical' DESC")->orderBy('raised_at')
                ->get(['key', 'severity', 'title', 'detail', 'raised_at'])
                ->map(fn (object $a) => ['key' => $a->key, 'severity' => $a->severity, 'title' => $a->title, 'detail' => $a->detail, 'raised_at' => self::time($a->raised_at)])->all(),
        ];
    }

    /** @return list<array{name: string, ok: bool, detail: string}> */
    private function services(): array
    {
        $services = [['name' => 'Application', 'ok' => true, 'detail' => 'PHP '.PHP_VERSION.', Laravel '.app()->version()]];
        foreach ($this->services->all() as $name => $service) {
            $services[] = ['name' => $name, ...$service];
        }

        return $services;
    }

    /** @return array<string, mixed> */
    private function queues(): array
    {
        $waits = Checks::queueWaits();
        $queues = [];
        foreach (['redis' => ['default'], 'redis-reports' => ['reports']] as $connection => $names) {
            foreach ($names as $name) {
                try {
                    $size = Queue::connection($connection)->size($name);
                } catch (Throwable) {
                    $size = null;
                }
                $queues[] = ['name' => $name, 'size' => $size, 'wait_seconds' => $waits["{$connection}:{$name}"] ?? null];
            }
        }

        return ['queues' => $queues, 'failed_24h' => Checks::failedJobs(), 'failed_total' => (int) DB::table('failed_jobs')->count()];
    }

    /** @return array<string, mixed> */
    private function scheduler(): array
    {
        $runs = [];
        foreach (HealthStatus::prefixed('schedule.') as $key => $run) {
            $runs[] = ['command' => substr($key, strlen('schedule.')), 'ok' => $run['ok'], 'at' => self::time($run['at']), 'seconds' => $run['data']['seconds'] ?? null, 'detail' => $run['detail']];
        }

        return ['last_run' => self::time(Checks::lastScheduledRun()), 'runs' => $runs];
    }

    /** @return array<string, mixed> */
    private function backups(): array
    {
        $wal = $this->backups->wal();
        $pgSizes = $this->backups->json('pgbackrest/sizes.json') ?? [];
        $resticSizes = $this->backups->json('restic/sizes.json') ?? [];
        $snapshots = $this->backups->json('restic/snapshots.json') ?? [];
        $drill = $this->backups->json('drill/last.json');
        $task = fn (string $service, string $task) => ($t = $this->backups->task($service, $task)) === null ? null : ['ok' => $t['ok'], 'at' => self::time($t['at']), 'message' => $t['message']];
        $backup = fn (?array $b) => $b === null ? null : ['label' => $b['label'], 'finished_at' => self::time($b['finished_at']), 'seconds' => $b['finished_at']->getTimestamp() - $b['started_at']->getTimestamp(), 'size' => $b['size'], 'repo_size' => $b['repo_size']];
        $newestSnapshot = collect($snapshots)->map(fn ($s) => is_array($s) && isset($s['time']) ? CarbonImmutable::parse((string) $s['time']) : null)->filter()->max();

        return [
            'available' => $this->backups->available(),
            'database' => [
                'newest_full' => $backup($this->backups->newestDatabaseBackup('full')),
                'newest_diff' => $backup($this->backups->newestDatabaseBackup('diff')),
                'count' => count($this->backups->databaseBackups()),
                'wal_last_archived_at' => self::time($wal['last_archived_at'] ?? null),
                'wal_oldest_waiting_seconds' => $wal['oldest_ready_seconds'] ?? null,
                'reported_at' => self::time($wal['checked_at'] ?? null),
                'check' => $task('pgbackrest', 'check'),
                'verify' => $task('pgbackrest', 'verify'),
                'last_backup' => $task('pgbackrest', 'backup-diff') ?? $task('pgbackrest', 'backup-full'),
                'repo1_bytes' => isset($pgSizes['repo1_kb']) ? (int) $pgSizes['repo1_kb'] * 1024 : null,
                'repo2_bytes' => isset($pgSizes['repo2_kb']) ? (int) $pgSizes['repo2_kb'] * 1024 : null,
            ],
            'evidence' => [
                'last_backup' => $task('restic', 'backup'),
                'snapshots' => count($snapshots),
                'newest_snapshot_at' => self::time($newestSnapshot),
                'check' => $task('restic', 'check'),
                'check_data' => $task('restic', 'check-data'),
                'repo1_bytes' => isset($resticSizes['repo1_kb']) ? (int) $resticSizes['repo1_kb'] * 1024 : null,
                'repo2_bytes' => isset($resticSizes['repo2_kb']) ? (int) $resticSizes['repo2_kb'] * 1024 : null,
            ],
            'repository_disk' => isset($pgSizes['free_kb'], $pgSizes['total_kb']) && (int) $pgSizes['total_kb'] > 0
                ? ['free' => (int) $pgSizes['free_kb'] * 1024, 'total' => (int) $pgSizes['total_kb'] * 1024, 'used_percent' => round(100 * (1 - (int) $pgSizes['free_kb'] / (int) $pgSizes['total_kb']), 1)]
                : null,
            'drill' => $drill === null ? null : [
                'at' => self::time(isset($drill['at']) ? CarbonImmutable::parse((string) $drill['at']) : null),
                'result' => (string) ($drill['result'] ?? 'unknown'), 'seconds' => (int) ($drill['seconds'] ?? 0),
                'within_rto' => (bool) ($drill['within_rto'] ?? false), 'mode' => (string) ($drill['mode'] ?? ''),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function storage(): array
    {
        $disks = [];
        foreach (Checks::diskUsage() as $name => $usage) {
            $disks[] = ['name' => $name, ...($usage ?? ['path' => null, 'free' => null, 'total' => null, 'used_percent' => null])];
        }

        return [
            'disks' => $disks,
            'database_bytes' => (int) DB::scalar('SELECT pg_database_size(current_database())'),
            'largest_tables' => collect(DB::select("SELECT c.relname AS name, pg_total_relation_size(c.oid) AS bytes FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' ORDER BY 2 DESC LIMIT 8"))
                ->map(fn (\stdClass $t) => ['name' => (string) $t->name, 'bytes' => (int) $t->bytes])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function audit(): array
    {
        $verify = HealthStatus::get('audit.verify');
        $anchors = DB::table('audit_anchors as a')
            ->whereRaw('a.id = (SELECT max(b.id) FROM audit_anchors b WHERE b.table_name = a.table_name)')
            ->orderBy('a.table_name')->get(['a.table_name', 'a.head_seq', 'a.head_hash', 'a.created_at']);

        return [
            'last_verify' => $verify === null ? null : ['ok' => $verify['ok'], 'at' => self::time($verify['at']), 'detail' => $verify['detail']],
            'anchors' => $anchors->map(fn (object $a) => ['table' => (string) $a->table_name, 'seq' => (int) $a->head_seq, 'hash' => (string) $a->head_hash, 'at' => self::time($a->created_at)])->all(),
        ];
    }

    private static function time(CarbonInterface|string|null $at): ?string
    {
        if ($at === null) {
            return null;
        }

        return ($at instanceof CarbonInterface ? $at : CarbonImmutable::parse($at))->toIso8601String();
    }
}
