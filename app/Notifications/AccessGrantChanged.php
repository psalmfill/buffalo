<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * A grant the user holds expires soon, has expired, or was revoked (Phase 3C).
 * Payloads never contain person details (BuffaloNotification): only a
 * link, which is access-checked when opened.
 */
final class AccessGrantChanged extends BuffaloNotification
{
    public function __construct(
        public readonly string $requestUlid,
        public readonly string $change,
        public readonly string $expiresAt,
    ) {}

    public function type(): string
    {
        return 'access_grant_changed';
    }

    public function title(User $notifiable): string
    {
        return match ($this->change) {
            'expiring' => 'Temporary access expires soon', 'revoked' => 'Temporary access revoked', default => 'Temporary access ended'
        };
    }

    public function body(User $notifiable): string
    {
        return match ($this->change) {
            'expiring' => "Your temporary access to a record expires at {$this->expiresAt}.", 'revoked' => 'Your temporary access to a record was revoked by the owning state.', default => 'Your temporary access to a record has ended.'
        };
    }

    public function url(User $notifiable): string
    {
        return '/access-requests/'.$this->requestUlid;
    }

    public function severity(): NotificationSeverity
    {
        return $this->change === 'revoked' ? NotificationSeverity::Warning : NotificationSeverity::Info;
    }
}
