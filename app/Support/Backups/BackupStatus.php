<?php

namespace App\Support\Backups;

use Carbon\CarbonImmutable;

/**
 * Reads the status the backup sidecars write (Phase 8C): JSON files on the
 * backup_status volume, mounted read-only at buffalo.backups.status_path.
 * Holds times, results and sizes only; never data or secrets.
 *
 * pgbackrest/: info.json (pgbackrest info), wal.json (archiving), sizes.json,
 *   and one file per task (backup-full, backup-diff, check, verify).
 * restic/: snapshots.json, sizes.json, and one file per task (backup,
 *   forget, check, check-data).
 */
final class BackupStatus
{
    public function __construct(private readonly string $path) {}

    public static function make(): self
    {
        return new self((string) config('buffalo.backups.status_path'));
    }

    public function available(): bool
    {
        return is_dir($this->path.'/pgbackrest');
    }

    /** @return array<array-key, mixed>|null */
    public function json(string $file): ?array
    {
        $path = $this->path.'/'.$file;
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * A task's last result.
     *
     * @return array{ok: bool, at: CarbonImmutable, message: string}|null
     */
    public function task(string $service, string $task): ?array
    {
        $data = $this->json("{$service}/{$task}.json");
        if ($data === null || ! isset($data['at'])) {
            return null;
        }

        return ['ok' => (bool) ($data['ok'] ?? false), 'at' => CarbonImmutable::parse((string) $data['at']), 'message' => (string) ($data['message'] ?? '')];
    }

    /**
     * Database backups in the repository, newest last.
     *
     * @return list<array{label: string, type: string, started_at: CarbonImmutable, finished_at: CarbonImmutable, size: int, repo_size: int}>
     */
    public function databaseBackups(): array
    {
        $info = $this->json('pgbackrest/info.json');
        $stanza = is_array($info) && isset($info[0]) && is_array($info[0]) ? $info[0] : [];
        $backups = [];

        foreach ((array) ($stanza['backup'] ?? []) as $backup) {
            if (! is_array($backup) || ! isset($backup['timestamp']['stop'])) {
                continue;
            }
            $backups[] = [
                'label' => (string) $backup['label'],
                'type' => (string) $backup['type'],
                'started_at' => CarbonImmutable::createFromTimestamp((int) $backup['timestamp']['start']),
                'finished_at' => CarbonImmutable::createFromTimestamp((int) $backup['timestamp']['stop']),
                'size' => (int) ($backup['info']['size'] ?? 0),
                'repo_size' => (int) ($backup['info']['repository']['size'] ?? 0),
            ];
        }

        return $backups;
    }

    /** @return array{label: string, type: string, started_at: CarbonImmutable, finished_at: CarbonImmutable, size: int, repo_size: int}|null */
    public function newestDatabaseBackup(?string $type = null): ?array
    {
        $backups = array_values(array_filter($this->databaseBackups(), fn (array $b) => $type === null || $b['type'] === $type));

        return $backups === [] ? null : $backups[count($backups) - 1];
    }

    /**
     * WAL archiving: when the sidecar last looked, the oldest segment waiting
     * to be archived, and PostgreSQL's archiver statistics.
     *
     * @return array{checked_at: CarbonImmutable, oldest_ready_seconds: int, last_archived_at: CarbonImmutable|null, last_failed_at: CarbonImmutable|null, failed_count: int}|null
     */
    public function wal(): ?array
    {
        $wal = $this->json('pgbackrest/wal.json');
        if ($wal === null || ! isset($wal['at'])) {
            return null;
        }
        $stats = is_array($wal['stats'] ?? null) ? $wal['stats'] : [];

        return [
            'checked_at' => CarbonImmutable::parse((string) $wal['at']),
            'oldest_ready_seconds' => (int) ($wal['oldest_ready_seconds'] ?? 0),
            'last_archived_at' => isset($stats['last_archived_time']) ? CarbonImmutable::parse((string) $stats['last_archived_time']) : null,
            'last_failed_at' => isset($stats['last_failed_time']) ? CarbonImmutable::parse((string) $stats['last_failed_time']) : null,
            'failed_count' => (int) ($stats['failed_count'] ?? 0),
        ];
    }
}
