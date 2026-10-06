<?php

namespace App\Support\Health;

use App\Support\Malware\MalwareScanner;
use App\Support\Malware\ScannerUnavailable;
use Carbon\CarbonImmutable;

/**
 * Alert conditions for the stack (Phase 8C.4):
 * - a service probe failing (critical; ClamAV down is reported here too);
 * - disk use over 80% (warning) or 90% (critical);
 * - more failed jobs than buffalo.health.failed_jobs in 24 hours (warning);
 * - a queue wait over buffalo.health.queue_wait_minutes (warning);
 * - ClamAV signatures older than buffalo.malware.max_signature_age_days (warning);
 * - the audit verification not run for 26 hours (warning);
 * - the TLS certificate expiring within 30 (warning) or 7 days (critical).
 * The scheduler is checked by SchedulerCheck (also from a watchdog job).
 */
final class StackChecks implements HealthCheck
{
    public function __construct(private readonly ServiceProbe $services) {}

    public function prefix(): string
    {
        return 'stack.';
    }

    public function conditions(): array
    {
        $conditions = [];

        foreach ($this->services->all() as $name => $service) {
            if (! $service['ok']) {
                $key = 'stack.service.'.strtolower($name);
                $conditions[] = Condition::critical($key, "{$name} is not responding", "{$name}: {$service['detail']}");
            }
        }

        foreach (Checks::diskUsage() as $name => $disk) {
            if ($disk === null) {
                continue;
            }
            $key = 'stack.disk.'.preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
            $detail = "{$name} ({$disk['path']}) is {$disk['used_percent']}% full: ".self::bytes($disk['free']).' free of '.self::bytes($disk['total']).'.';
            if ($disk['used_percent'] > (float) config('buffalo.health.disk_critical_percent')) {
                $conditions[] = Condition::critical($key, "Disk almost full: {$name}", $detail);
            } elseif ($disk['used_percent'] > (float) config('buffalo.health.disk_warning_percent')) {
                $conditions[] = Condition::warning($key, "Disk filling up: {$name}", $detail);
            }
        }

        $failed = Checks::failedJobs();
        if ($failed > (int) config('buffalo.health.failed_jobs')) {
            $conditions[] = Condition::warning('stack.queue.failed', 'Many failed jobs', "{$failed} queued jobs failed in the last 24 hours. Review them in Horizon.");
        }

        $maxWait = (int) config('buffalo.health.queue_wait_minutes') * 60;
        foreach (Checks::queueWaits() as $queue => $seconds) {
            if ($seconds > $maxWait) {
                $conditions[] = Condition::warning('stack.queue.wait.'.preg_replace('/[^a-z0-9]+/', '_', strtolower($queue)), 'Queue backlog', "Jobs on {$queue} are waiting about ".intdiv($seconds, 60).' minutes.');
            }
        }

        try {
            $age = app(MalwareScanner::class)->version()->signatureAgeDays();
            $max = (int) config('buffalo.malware.max_signature_age_days');
            if ($age === null || $age > $max) {
                $conditions[] = Condition::warning('stack.clamav.signatures', 'Malware signatures out of date', 'The malware signatures are '.($age === null ? 'of unknown age' : "{$age} days old")." (limit {$max}). Import current signature files (make clamav-import-signatures) or enable online updates.");
            }
        } catch (ScannerUnavailable) {
            // Reported as the ClamAV service probe above.
        }

        $audit = HealthStatus::get('audit.verify');
        if ($audit === null || $audit['at']->lt(CarbonImmutable::now()->subHours(26))) {
            $conditions[] = Condition::warning('stack.audit.not_run', 'Audit verification has not run', 'buffalo:audit:verify has not completed a full run in 26 hours (it runs nightly at 02:30).');
        }

        $certificate = self::certificateExpiry();
        if ($certificate !== null) {
            $days = (int) floor(CarbonImmutable::now()->diffInDays($certificate, false));
            if ($days < 7) {
                $conditions[] = Condition::critical('stack.tls.expiry', 'TLS certificate expiring', "The TLS certificate expires in {$days} days ({$certificate->toDateString()}).");
            } elseif ($days < 30) {
                $conditions[] = Condition::warning('stack.tls.expiry', 'TLS certificate expiring soon', "The TLS certificate expires in {$days} days ({$certificate->toDateString()}).");
            }
        }

        return $conditions;
    }

    /** Expiry of the configured TLS certificate (Phase 9), or null. */
    public static function certificateExpiry(): ?CarbonImmutable
    {
        $path = (string) config('buffalo.health.tls_certificate');
        if ($path === '' || ! is_readable($path)) {
            return null;
        }
        $certificate = @openssl_x509_parse((string) file_get_contents($path));

        return is_array($certificate) && isset($certificate['validTo_time_t']) ? CarbonImmutable::createFromTimestamp((int) $certificate['validTo_time_t']) : null;
    }

    public static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1).' '.$units[$i];
    }
}
