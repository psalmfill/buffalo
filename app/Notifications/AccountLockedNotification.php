<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;

/**
 * Tells super admins that an account was locked after repeated failed
 * sign-in attempts. Database channel only (notification UI: Phase 1D).
 */
class AccountLockedNotification extends Notification
{
    public function __construct(
        public readonly User $lockedUser,
        public readonly CarbonInterface $lockedUntil,
        public readonly ?string $ipAddress,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => 'account_locked',
            'user_ulid' => $this->lockedUser->ulid,
            'user_name' => $this->lockedUser->name,
            'user_email' => $this->lockedUser->email,
            'locked_until' => $this->lockedUntil->toIso8601String(),
            'ip_address' => $this->ipAddress,
            'message' => "{$this->lockedUser->name}'s account was locked after repeated failed sign-in attempts.",
        ];
    }
}
