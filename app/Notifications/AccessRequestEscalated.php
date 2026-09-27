<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * An access request was not decided in time and is escalated to national oversight (Phase 3C).
 * Payloads never contain person details (BuffaloNotification): only a
 * link, which is access-checked when opened.
 */
final class AccessRequestEscalated extends BuffaloNotification
{
    public function __construct(
        public readonly string $requestUlid,
        public readonly string $owningState,
        public readonly bool $urgent,
    ) {}

    public function type(): string
    {
        return 'access_request_escalated';
    }

    public function title(User $notifiable): string
    {
        return 'Access request escalated';
    }

    public function body(User $notifiable): string
    {
        return "An access request to a record of {$this->owningState} was not decided in time and is escalated to you.";
    }

    public function url(User $notifiable): string
    {
        return '/access-requests/'.$this->requestUlid;
    }

    public function severity(): NotificationSeverity
    {
        return $this->urgent ? NotificationSeverity::Critical : NotificationSeverity::Warning;
    }
}
