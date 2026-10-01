<?php

namespace App\Support\Notifications;

use App\Enums\Role;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Notifications\BuffaloNotification;
use App\Support\Jurisdiction\NotificationRecipients;
use Closure;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a notification to every active user with a role within a
 * jurisdiction, e.g. all state directors of Lagos, or all super admins.
 * See NotificationRecipients for which users a place covers. $only, when
 * given, keeps the recipients for whom it returns true (e.g. those who can
 * see the record concerned).
 */
final class NotifyRole
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    /**
     * @return int number of recipients
     */
    /**
     * @param  (Closure(User): bool)|null  $only
     */
    public function send(BuffaloNotification $notification, Role $role, State|Lga|null $place = null, ?Closure $only = null): int
    {
        $users = $this->recipients->resolve($role, $place);

        if ($only !== null) {
            $users = $users->filter($only)->values();
        }

        Notification::send($users, $notification);

        return $users->count();
    }
}
