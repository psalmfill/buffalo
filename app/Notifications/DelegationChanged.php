<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * An approval delegation was created, revoked or ended (Phase 3C). Sent to both parties.
 * Payloads never contain person details (BuffaloNotification): only a
 * link, which is access-checked when opened.
 */
final class DelegationChanged extends BuffaloNotification
{
    public function __construct(
        public readonly string $change,
        public readonly string $delegator,
        public readonly string $delegate,
    ) {}

    public function type(): string
    {
        return 'delegation_changed';
    }

    public function title(User $notifiable): string
    {
        return match ($this->change) {
            'created' => 'Approval delegation created', 'revoked' => 'Approval delegation revoked', default => 'Approval delegation ended'
        };
    }

    public function body(User $notifiable): string
    {
        return match ($this->change) {
            'created' => "{$this->delegator} delegated the approval of access requests to {$this->delegate}.", 'revoked' => "The delegation from {$this->delegator} to {$this->delegate} was revoked.", default => "The delegation from {$this->delegator} to {$this->delegate} ended because a posting changed."
        };
    }

    public function url(User $notifiable): string
    {
        return '/access-requests?tab=delegations';
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Info;
    }
}
