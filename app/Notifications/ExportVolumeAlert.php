<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * An officer's exports passed an unusual-volume threshold today (Phase
 * 8B.3). Sent to the DG and the DDGs covering the officer's jurisdiction,
 * once per officer, rule and day. Payload: the officer's name, role,
 * jurisdiction and today's counts; never record details.
 */
final class ExportVolumeAlert extends BuffaloNotification
{
    /**
     * @param  array{rows: int, report_runs: int, evidence_downloads: int, secret_exports: int, sensitive_exports?: int}  $usage
     * @param  array{rows: int, report_runs: int, evidence_downloads: int}  $quotas
     */
    public function __construct(
        public readonly string $officer,
        public readonly string $role,
        public readonly string $jurisdiction,
        public readonly string $rule,
        public readonly array $usage,
        public readonly array $quotas,
    ) {}

    public function type(): string
    {
        return 'export_volume_alert';
    }

    public function title(User $notifiable): string
    {
        return 'Unusual export volume';
    }

    public function body(User $notifiable): string
    {
        $reason = match ($this->rule) {
            'secret_exports' => "exported secret material {$this->usage['secret_exports']} times",
            'half_rows' => 'exported '.number_format($this->usage['rows']).' rows (limit '.number_format($this->quotas['rows']).')',
            'half_report_runs' => "requested {$this->usage['report_runs']} reports (limit {$this->quotas['report_runs']})",
            default => "downloaded {$this->usage['evidence_downloads']} evidence originals (limit {$this->quotas['evidence_downloads']})",
        };

        return "{$this->officer} ({$this->role}, {$this->jurisdiction}) has {$reason} today. "
            ."Today: {$this->usage['report_runs']} reports, ".number_format($this->usage['rows'])." rows, {$this->usage['evidence_downloads']} evidence downloads, {$this->usage['secret_exports']} secret exports.";
    }

    public function url(User $notifiable): string
    {
        return '/audit/exports';
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Warning;
    }
}
