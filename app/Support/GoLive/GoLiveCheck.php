<?php

namespace App\Support\GoLive;

use App\Enums\GoLiveDecision;
use App\Enums\Role;
use App\Models\GoLiveDecisionRecord;
use App\Models\User;
use App\Support\Backups\BackupStatus;
use App\Support\Edge\EdgeMode;
use App\Support\Health\HealthStatus;
use App\Support\Malware\MalwareScanner;
use App\Support\Uat\Uat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The go-live check (Phase 9B.6). Technical items must pass; client
 * decisions must be recorded. Runs in the console (system context), daily
 * and on demand; the result is stored for the read-only view on System
 * health. Details name settings, counts and dates only, never secrets.
 *
 * Statuses: pass, fail, manual (checked outside BUFFALO; not blocking) for
 * technical items; recorded, missing, fail for decisions.
 *
 * @phpstan-type Item array{section: string, key: string, label: string, status: string, detail: string}
 */
final class GoLiveCheck
{
    public const STATUS_KEY = 'golive.check';

    public const SCAN_KEY = 'release.scan';

    public function __construct(
        private readonly DataStatus $data,
        private readonly BackupStatus $backups,
        private readonly MalwareScanner $scanner,
        private readonly TlsProbe $tls,
        private readonly BootChecks $boot,
    ) {}

    /**
     * @return array{passed: bool, version: string, checked_at: string, items: list<Item>}
     */
    public function run(): array
    {
        $items = [
            $this->bootChecks(),
            $this->edgeMode(),
            $this->tlsCertificate(),
            $this->realTimeOrigin(),
            ...$this->dataItems(),
            $this->superAdmin(),
            $this->developmentAccounts(),
            ...$this->backupItems(),
            $this->restoreDrill(),
            $this->malwareSignatures(),
            $this->auditChains(),
            $this->vulnerabilityExceptions(),
            $this->securityScan(),
            ...$this->decisions(),
        ];

        // `manual`: checked by people outside BUFFALO (the host nginx's
        // certificate in edge mode proxy); shown, but never blocking.
        $passed = collect($items)->every(fn (array $item): bool => in_array($item['status'], ['pass', 'recorded', 'manual'], true));

        return [
            'passed' => $passed,
            'version' => (string) config('buffalo.golive.version'),
            'checked_at' => now()->toIso8601String(),
            'items' => $items,
        ];
    }

    /** A date and time in the application's time zone, with the zone. */
    private static function time(CarbonImmutable $at): string
    {
        return $at->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i T');
    }

    /**
     * @return Item
     */
    private static function item(string $key, string $label, bool $ok, string $detail, string $section = 'technical'): array
    {
        return ['section' => $section, 'key' => $key, 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail];
    }

    /**
     * Production mode and the start-up checks of Phase 9A/8A.
     *
     * @return Item
     */
    private function bootChecks(): array
    {
        $failed = $this->boot->failing();

        return self::item('boot_checks', 'Production mode and start-up checks', $failed === [],
            $failed === [] ? 'buffalo:production:check, buffalo:secrets:check and buffalo:keys:verify pass.' : 'Failing: '.implode(', ', $failed).'. Run them for the details.');
    }

    /**
     * The edge mode and its port, and whether the settings are consistent
     * (the same rules as the start-up check).
     *
     * @return Item
     */
    private function edgeMode(): array
    {
        $problems = EdgeMode::problems(Uat::enabled());

        return self::item('edge', 'Edge mode and port ('.EdgeMode::mode().')', $problems === [],
            $problems === [] ? EdgeMode::summary() : implode('; ', $problems).'.');
    }

    /** @return Item */
    private function tlsCertificate(): array
    {
        $min = (int) config('buffalo.golive.tls_min_days');

        if (EdgeMode::mode() === EdgeMode::PROXY) {
            return ['section' => 'technical', 'key' => 'tls', 'label' => "TLS certificate valid for at least {$min} days", 'status' => 'manual',
                'detail' => 'Edge mode proxy: the host\'s nginx terminates TLS, and BUFFALO cannot read its certificate. Check its expiry and automatic renewal on the host.'];
        }

        $expiry = $this->tls->servedCertificateExpiry();

        if ($expiry === null) {
            return self::item('tls', "TLS certificate valid for at least {$min} days", false, 'No certificate could be read from nginx on the internal network.');
        }

        $days = (int) floor(CarbonImmutable::now()->diffInDays($expiry, false));

        return self::item('tls', "TLS certificate valid for at least {$min} days", $days >= $min, "The served certificate expires on {$expiry->toDateString()} ({$days} days).");
    }

    /**
     * Reverb accepts WebSocket connections only from the allowed origins: the
     * host users open BUFFALO on must be one of them, or live updates
     * (report status, notifications, incidents) silently stop (found by the
     * Phase 11 browser check in UAT).
     *
     * @return Item
     */
    private function realTimeOrigin(): array
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $allowed = array_values(array_filter(array_map('trim', (array) config('reverb.apps.apps.0.allowed_origins'))));
        $ok = $host !== '' && collect($allowed)->contains(fn (string $origin) => $origin === '*' || Str::is($origin, $host));

        return self::item('realtime_origin', 'Live updates accept the site\'s address (REVERB_ALLOWED_ORIGINS)', $ok, $ok
            ? "WebSocket connections from {$host} are accepted."
            : "REVERB_ALLOWED_ORIGINS does not include {$host} (the APP_URL host): browsers cannot receive live updates.");
    }

    /**
     * @return list<Item>
     */
    private function dataItems(): array
    {
        $status = $this->data->build();
        $h = $status['hierarchy'];
        $demo = array_values(array_filter($status['demo'], fn (array $d) => $d['kind'] !== 'test_accounts'));
        $hierarchyOk = $h['states'] >= $h['expected']['states'] && $h['districts'] >= $h['expected']['districts'] && $h['lgas'] >= $h['expected']['lgas'];
        $boundaries = $status['boundaries']['lgas_with_boundary'];

        return [
            self::item('demo_data', 'No demo or synthetic data', $demo === [],
                $demo === [] ? 'buffalo:data:status finds no demo or synthetic data.' : implode('; ', array_map(fn ($d) => "{$d['count']} × {$d['detail']}", $demo)).'.'),
            self::item('hierarchy', 'Hierarchy loaded (37 states, 109 districts, 774 LGAs)', $hierarchyOk,
                sprintf('%d states, %d senatorial districts, %d LGAs.', $h['states'], $h['districts'], $h['lgas'])),
            self::item('boundaries', 'LGA boundaries loaded', $h['lgas'] > 0 && $boundaries >= $h['lgas'],
                sprintf('%d of %d LGAs have a boundary.', $boundaries, $h['lgas'])),
        ];
    }

    /** @return Item */
    private function superAdmin(): array
    {
        $count = User::query()->role(Role::SuperAdmin->value)->where('is_active', true)->whereNotNull('two_factor_confirmed_at')->count();

        return self::item('super_admin', 'At least one active super admin with 2FA', $count > 0, "{$count} active super admin(s) with two-factor authentication.");
    }

    /** @return Item */
    private function developmentAccounts(): array
    {
        $count = DB::table('users')->whereNull('deleted_at')->pluck('email')
            ->filter(fn ($email): bool => preg_match(DataStatus::RESERVED_DOMAIN_PATTERN, (string) $email) === 1)
            ->count();

        return self::item('dev_accounts', 'No development or test accounts', $count === 0,
            $count === 0 ? 'No account uses a reserved test domain.' : "{$count} account(s) on reserved test domains (.test, .local, .example, ...): deactivate or remove them.");
    }

    /**
     * @return list<Item>
     */
    private function backupItems(): array
    {
        $maxHours = (int) config('buffalo.backups.max_age_hours');
        $fullDays = (int) config('buffalo.golive.full_backup_max_days');
        $full = $this->backups->newestDatabaseBackup('full');
        $newest = $this->backups->newestDatabaseBackup();
        $fullOk = $full !== null && $full['finished_at']->greaterThan(now()->subDays($fullDays));
        $recentOk = $newest !== null && $newest['finished_at']->greaterThan(now()->subHours($maxHours));

        $snapshots = $this->backups->json('restic/snapshots.json') ?? [];
        $newestSnapshot = collect($snapshots)->map(fn ($s) => is_array($s) && isset($s['time']) ? CarbonImmutable::parse((string) $s['time']) : null)->filter()->max();
        $snapshotOk = $newestSnapshot instanceof CarbonImmutable && $newestSnapshot->greaterThan(now()->subHours($maxHours));

        $pgSizes = $this->backups->json('pgbackrest/sizes.json') ?? [];
        $resticSizes = $this->backups->json('restic/sizes.json') ?? [];
        $repo2 = ($pgSizes['repo2_kb'] ?? null) !== null && ($resticSizes['repo2_kb'] ?? null) !== null;

        return [
            self::item('backup_database', 'Recent full database backup', $fullOk && $recentOk, sprintf(
                'Newest full backup: %s (at most %d days old required); newest backup: %s (at most %d hours).',
                $full === null ? 'none' : self::time($full['finished_at']), $fullDays,
                $newest === null ? 'none' : self::time($newest['finished_at']), $maxHours,
            )),
            self::item('backup_evidence', 'Recent evidence snapshot', $snapshotOk, sprintf(
                'Newest snapshot: %s (at most %d hours old required).', $newestSnapshot instanceof CarbonImmutable ? self::time($newestSnapshot) : 'none', $maxHours,
            )),
            self::item('backup_repo2', 'Second backup repository configured', $repo2,
                $repo2 ? 'Both the database and evidence backups report a second repository.' : 'Repository 2 is not in use (BACKUP_REPO2_ENABLED, BACKUP_REPO2_PATH).'),
        ];
    }

    /** @return Item */
    private function restoreDrill(): array
    {
        $drill = $this->backups->json('drill/last.json');
        $maxDays = (int) config('buffalo.golive.drill_max_days');
        $at = isset($drill['at']) ? CarbonImmutable::parse((string) $drill['at']) : null;
        $ok = $at !== null
            && $at->greaterThan(now()->subDays($maxDays))
            && ($drill['result'] ?? '') === 'pass'
            && in_array($drill['mode'] ?? '', ['full', 'pitr'], true)
            && ($drill['within_rto'] ?? false) === true;

        return self::item('restore_drill', "Restore drill on this server within {$maxDays} days, passing", $ok, $drill === null
            ? 'No restore drill has been recorded on this server (scripts/restore/drill.sh).'
            : sprintf('Last drill: %s, %s mode, result %s, %s the RTO%s.', $at instanceof CarbonImmutable ? self::time($at) : 'unknown', $drill['mode'] ?? '?', $drill['result'] ?? '?',
                ($drill['within_rto'] ?? false) ? 'within' : 'NOT within', isset($drill['host']) ? ', host '.$drill['host'] : ''));
    }

    /** @return Item */
    private function malwareSignatures(): array
    {
        $max = (int) config('buffalo.malware.max_signature_age_days');

        try {
            $age = $this->scanner->version()->signatureAgeDays();
        } catch (Throwable) {
            return self::item('clamav', "ClamAV signatures under {$max} days old", false, 'The malware scanner is not reachable.');
        }

        return self::item('clamav', "ClamAV signatures under {$max} days old", $age !== null && $age < $max,
            $age === null ? 'The signature date is unknown.' : "Signatures are {$age} day(s) old.");
    }

    /** @return Item */
    private function auditChains(): array
    {
        $hours = (int) config('buffalo.golive.audit_verify_max_hours');
        $verify = HealthStatus::get('audit.verify');
        $ok = $verify !== null && $verify['ok'] === true && $verify['at']->greaterThan(now()->subHours($hours));

        return self::item('audit_chains', "Audit chains verified within {$hours} hours", $ok, $verify === null
            ? 'buffalo:audit:verify has not run yet.'
            : sprintf('Last verification %s: %s', self::time($verify['at']), $verify['detail'] ?? ($verify['ok'] ? 'intact' : 'broken')));
    }

    /** @return Item */
    private function vulnerabilityExceptions(): array
    {
        $path = (string) config('buffalo.golive.exceptions_file');
        if (! is_file($path)) {
            return self::item('trivy_exceptions', 'Vulnerability exceptions not expired', false, 'security/vulnerability-exceptions.yaml is missing from this image.');
        }

        preg_match_all('/^\s*expired_at:\s*["\']?(\d{4}-\d{2}-\d{2})/m', (string) file_get_contents($path), $matches);
        $dates = array_map(fn (string $date) => CarbonImmutable::parse($date), $matches[1]);
        $expired = array_filter($dates, fn (CarbonImmutable $date) => $date->isPast());
        $next = collect($dates)->min();

        return self::item('trivy_exceptions', 'Vulnerability exceptions not expired', $expired === [], $expired !== []
            ? count($expired).' exception(s) past their review date: review them and publish a new release.'
            : sprintf('%d exception(s); next review %s.', count($dates), $next?->toDateString() ?? 'none'));
    }

    /** @return Item */
    private function securityScan(): array
    {
        $version = (string) config('buffalo.golive.version');
        $scan = HealthStatus::get(self::SCAN_KEY);
        $data = $scan['data'] ?? [];
        $ok = ($data['version'] ?? null) === $version && ($data['result'] ?? null) === 'passed';

        return self::item('security_scan', 'Security scan passed for the installed version', $ok, $scan === null
            ? "No security scan is recorded for version {$version} (recorded by install.sh / upgrade.sh from the signed release)."
            : sprintf('Scan of version %s on %s: %s.', $data['version'] ?? '?', $data['scanned_at'] ?? '?', $data['result'] ?? '?')
                .(($data['version'] ?? null) !== $version ? " The installed version is {$version}." : ''));
    }

    /**
     * @return list<Item>
     */
    private function decisions(): array
    {
        $current = GoLiveDecisionRecord::current();
        $referenceVersion = $this->data->referenceVersion();
        $items = [];

        foreach (GoLiveDecision::cases() as $decision) {
            $record = $current[$decision->value] ?? null;

            if ($record === null) {
                $items[] = ['section' => 'decision', 'key' => $decision->value, 'label' => $decision->label(), 'status' => 'missing', 'detail' => 'Not recorded yet.'];

                continue;
            }

            $detail = sprintf('%s — decided by %s on %s; recorded by %s.',
                $decision->outcomes()[$record->outcome] ?? $record->outcome, $record->client_representative,
                $record->decided_on->toDateString(), $record->recorder->name ?? 'unknown');

            if ($decision === GoLiveDecision::ReferenceData && $record->reference_version !== $referenceVersion) {
                $items[] = ['section' => 'decision', 'key' => $decision->value, 'label' => $decision->label(), 'status' => 'fail',
                    'detail' => "Version {$record->reference_version} was signed off; the reference data has changed since (now {$referenceVersion}). Record a new sign-off."];

                continue;
            }

            $items[] = ['section' => 'decision', 'key' => $decision->value, 'label' => $decision->label(), 'status' => 'recorded', 'detail' => $detail];
        }

        return $items;
    }
}
