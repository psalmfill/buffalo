<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * The requester's access request was approved or rejected (Phase 3C).
 * Payloads never contain person details (BuffaloNotification): only a
 * link, which is access-checked when opened.
 */
final class AccessRequestDecided extends BuffaloNotification
{
    public function __construct(
        public readonly string $requestUlid,
        public readonly bool $approved,
        public readonly string $owningState,
    ) {}

    public function type(): string
    {
        return 'access_request_decided';
    }

    public function title(User $notifiable): string
    {
        return $this->approved ? 'Access request approved' : 'Access request rejected';
    }

    public function body(User $notifiable): string
    {
        return $this->approved ? "Your access request to a record of {$this->owningState} was approved. The access is read-only and temporary." : "Your access request to a record of {$this->owningState} was rejected.";
    }

    public function url(User $notifiable): string
    {
        return '/access-requests/'.$this->requestUlid;
    }

    public function severity(): NotificationSeverity
    {
        return $this->approved ? NotificationSeverity::Info : NotificationSeverity::Warning;
    }
}
