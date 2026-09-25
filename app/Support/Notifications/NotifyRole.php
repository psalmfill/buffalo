<?php

namespace App\Support\Notifications;

use App\Enums\Role;
use App\Models\Lga;
use App\Models\State;
use App\Notifications\BuffaloNotification;
use App\Support\Jurisdiction\NotificationRecipients;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a notification to every active user with a role within a
 * jurisdiction, e.g. all state directors of Lagos, or all super admins.
 * See NotificationRecipients for which users a place covers.
 */
final class NotifyRole
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    /**
     * @return int number of recipients
     */
    public function send(BuffaloNotification $notification, Role $role, State|Lga|null $place = null): int
    {
        $users = $this->recipients->resolve($role, $place);

        Notification::send($users, $notification);

        return $users->count();
    }
}
