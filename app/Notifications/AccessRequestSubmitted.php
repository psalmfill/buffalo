<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * A new cross-jurisdiction access request to decide (Phase 3C). Urgent requests are critical (live toast).
 * Payloads never contain person details (BuffaloNotification): only a
 * link, which is access-checked when opened.
 */
final class AccessRequestSubmitted extends BuffaloNotification
{
    public function __construct(
        public readonly string $requestUlid,
        public readonly string $requester,
        public readonly string $requesterPlace,
        public readonly bool $urgent,
    ) {}

    public function type(): string
    {
        return 'access_request_submitted';
    }

    public function title(User $notifiable): string
    {
        return $this->urgent ? 'Urgent access request' : 'Access request';
    }

    public function body(User $notifiable): string
    {
        return "{$this->requester} ({$this->requesterPlace}) requests access to a record of your state. Review and decide.";
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
