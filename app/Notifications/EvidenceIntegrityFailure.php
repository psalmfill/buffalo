<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Enums\Role;
use App\Models\User;

/**
 * A stored evidence file no longer matches the hash recorded at upload
 * (Phase 5B): to the DG and super admins, critical. The payload is the
 * evidence ULID only. This is the only evidence event super admins are
 * told about; they get no link, since they cannot open intelligence.
 */
final class EvidenceIntegrityFailure extends BuffaloNotification
{
    public function __construct(public readonly string $evidenceUlid) {}

    public function type(): string
    {
        return 'evidence_integrity_failure';
    }

    public function title(User $notifiable): string
    {
        return 'Evidence integrity check failed';
    }

    public function body(User $notifiable): string
    {
        return "A stored evidence file (reference {$this->evidenceUlid}) does not match the hash recorded when it was uploaded. It cannot be downloaded until this is investigated.";
    }

    public function url(User $notifiable): ?string
    {
        return $notifiable->hasRole(Role::SuperAdmin->value) ? null : '/evidence/'.$this->evidenceUlid;
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Critical;
    }
}
