<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * An escalated access request was never decided and expired (Phase 3C).
 * Payloads never contain person details (BuffaloNotification): only a
 * link, which is access-checked when opened.
 */
final class AccessRequestExpired extends BuffaloNotification
{
    public function __construct(
        public readonly string $requestUlid,
        public readonly string $owningState,
    ) {}

    public function type(): string
    {
        return 'access_request_expired';
    }

    public function title(User $notifiable): string
    {
        return 'Access request expired';
    }

    public function body(User $notifiable): string
    {
        return "Your access request to a record of {$this->owningState} was not decided and has expired. You may request again from a new search.";
    }

    public function url(User $notifiable): string
    {
        return '/access-requests/'.$this->requestUlid;
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Warning;
    }
}
