<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * An operational condition was raised or has cleared (Phase 8C): backups,
 * disks, queues, services, signatures, audit verification. Super admins
 * (and the DG for audit integrity). Deduplicated by SystemAlerts: once per
 * condition until it clears. No intelligence content.
 */
final class SystemAlert extends BuffaloNotification
{
    /**
     * @param  'warning'|'critical'  $level
     */
    public function __construct(
        public readonly string $key,
        public readonly string $level,
        public readonly string $heading,
        public readonly string $detail,
        public readonly bool $cleared = false,
    ) {}

    public function type(): string
    {
        return 'system_alert';
    }

    public function title(User $notifiable): string
    {
        return $this->cleared ? "Resolved: {$this->heading}" : $this->heading;
    }

    public function body(User $notifiable): string
    {
        return $this->cleared ? 'This condition has cleared.' : $this->detail;
    }

    public function url(User $notifiable): ?string
    {
        return $notifiable->can('viewSystemHealth') ? '/system/health' : null;
    }

    public function severity(): NotificationSeverity
    {
        return match (true) {
            $this->cleared => NotificationSeverity::Info,
            $this->level === 'critical' => NotificationSeverity::Critical,
            default => NotificationSeverity::Warning,
        };
    }

    /** Critical system alerts also go by mail (generic subject, Phase 8B.5). */
    public function allowsMail(): bool
    {
        return $this->level === 'critical' && ! $this->cleared;
    }

    public function mailBody(User $notifiable): string
    {
        return 'A critical system condition needs attention. Sign in to System health for details.';
    }
}
