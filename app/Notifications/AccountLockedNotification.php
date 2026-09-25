<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Tells super admins that an account was locked after repeated failed
 * sign-in attempts.
 */
class AccountLockedNotification extends BuffaloNotification
{
    public function __construct(
        public readonly User $lockedUser,
        public readonly CarbonInterface $lockedUntil,
        public readonly ?string $ipAddress,
    ) {}

    public function type(): string
    {
        return 'account_locked';
    }

    public function title(User $notifiable): string
    {
        return 'Account locked';
    }

    public function body(User $notifiable): string
    {
        return "{$this->lockedUser->name}'s account was locked after repeated failed sign-in attempts"
            .($this->ipAddress !== null ? " from {$this->ipAddress}" : '')
            .', until '.$this->lockedUntil->format('j M Y H:i').'.';
    }

    public function url(User $notifiable): ?string
    {
        return '/users/'.$this->lockedUser->ulid;
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Warning;
    }
}
