<?php

namespace App\Support\Backups;

use App\Support\Health\Condition;
use App\Support\Health\HealthCheck;
use Carbon\CarbonImmutable;

/**
 * Backup alert conditions (Phase 8C.1, 8C.2), all critical:
 * a backup or check/verify failed; the newest backup is older than
 * buffalo.backups.max_age_hours (26); WAL archiving lags more than
 * buffalo.backups.wal_lag_minutes (15) or is failing; a backup sidecar has
 * stopped reporting.
 */
final class BackupChecks implements HealthCheck
{
    public function __construct(private readonly BackupStatus $status) {}

    public function prefix(): string
    {
        return 'backup.';
    }

    public function conditions(): array
    {
        if (! $this->status->available()) {
            return [Condition::critical('backup.status_missing', 'Backup status unavailable', 'The application cannot read the backup status (backup_status volume). Backups may not be running.')];
        }

        return [...$this->database(), ...$this->evidence()];
    }

    /** @return list<Condition> */
    private function database(): array
    {
        $conditions = [];
        $now = CarbonImmutable::now();
        $maxAge = (int) config('buffalo.backups.max_age_hours');

        $wal = $this->status->wal();
        if ($wal === null || $wal['checked_at']->lt($now->subMinutes(5))) {
            $conditions[] = Condition::critical('backup.db.sidecar', 'Database backup service not reporting', 'The pgbackrest service has not reported for more than 5 minutes. Scheduled database backups are not running.');
        }

        foreach (['backup-full' => 'Full database backup', 'backup-diff' => 'Differential database backup', 'check' => 'Database backup check', 'verify' => 'Database backup verification'] as $task => $label) {
            $result = $this->status->task('pgbackrest', $task);
            if ($result !== null && ! $result['ok']) {
                $conditions[] = Condition::critical("backup.db.{$task}", "{$label} failed", "{$label} failed at {$result['at']->setTimezone((string) config('app.timezone'))->format('j M Y H:i')}: {$result['message']}");
            }
        }

        $newest = $this->status->newestDatabaseBackup();
        if ($newest === null || $newest['finished_at']->lt($now->subHours($maxAge))) {
            $when = $newest === null ? 'There is no database backup.' : 'The newest database backup finished '.$newest['finished_at']->diffForHumans($now).'.';
            $conditions[] = Condition::critical('backup.db.stale', "No database backup in {$maxAge} hours", $when);
        }

        if ($wal !== null) {
            $lag = (int) config('buffalo.backups.wal_lag_minutes');
            if ($wal['oldest_ready_seconds'] > $lag * 60) {
                $conditions[] = Condition::critical('backup.db.wal_lag', 'WAL archiving is behind', 'The oldest WAL segment has waited '.intdiv($wal['oldest_ready_seconds'], 60)." minutes to be archived (limit {$lag}). Point-in-time recovery would lose more than the target.");
            } elseif ($wal['last_failed_at'] !== null && ($wal['last_archived_at'] === null || $wal['last_failed_at']->gt($wal['last_archived_at']))) {
                $conditions[] = Condition::critical('backup.db.wal_failing', 'WAL archiving is failing', 'PostgreSQL could not archive its latest WAL segment. Check the pgbackrest and postgres logs.');
            }
        }

        return $conditions;
    }

    /** @return list<Condition> */
    private function evidence(): array
    {
        // Until the evidence backup service has reported once, there is
        // nothing to check (e.g. a stack without the `backup` service).
        if ($this->status->json('restic/heartbeat.json') === null && $this->status->task('restic', 'backup') === null) {
            return [];
        }

        $conditions = [];
        $now = CarbonImmutable::now();
        $maxAge = (int) config('buffalo.backups.max_age_hours');

        $heartbeat = $this->status->json('restic/heartbeat.json');
        if ($heartbeat === null || CarbonImmutable::parse((string) $heartbeat['at'])->lt($now->subMinutes(5))) {
            $conditions[] = Condition::critical('backup.evidence.sidecar', 'Evidence backup service not reporting', 'The backup (restic) service has not reported for more than 5 minutes. Evidence backups are not running.');
        }

        foreach (['backup' => 'Evidence backup', 'forget' => 'Evidence backup retention', 'check' => 'Evidence backup check', 'check-data' => 'Evidence backup data check'] as $task => $label) {
            $result = $this->status->task('restic', $task);
            if ($result !== null && ! $result['ok']) {
                $conditions[] = Condition::critical("backup.evidence.{$task}", "{$label} failed", "{$label} failed at {$result['at']->setTimezone((string) config('app.timezone'))->format('j M Y H:i')}: {$result['message']}");
            }
        }

        $last = $this->status->task('restic', 'backup');
        $lastOk = $last !== null && $last['ok'] ? $last['at'] : $this->newestSnapshot();
        if ($lastOk === null || $lastOk->lt($now->subHours($maxAge))) {
            $conditions[] = Condition::critical('backup.evidence.stale', "No evidence backup in {$maxAge} hours", $lastOk === null ? 'There is no evidence backup.' : 'The newest evidence backup was taken '.$lastOk->diffForHumans($now).'.');
        }

        return $conditions;
    }

    private function newestSnapshot(): ?CarbonImmutable
    {
        $snapshots = $this->status->json('restic/snapshots.json');
        if (! is_array($snapshots) || $snapshots === []) {
            return null;
        }
        $times = array_map(fn ($s) => is_array($s) && isset($s['time']) ? CarbonImmutable::parse((string) $s['time']) : null, $snapshots);
        $times = array_filter($times);

        return $times === [] ? null : max($times);
    }
}
