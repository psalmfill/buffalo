<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\AccountSetupNotification;

/**
 * Email delivery of a setup link: issues a new link and mails it to the
 * user. The URL is not returned to the administrator.
 */
final class SendAccountSetupLink
{
    public function __construct(private readonly IssueAccountSetupLink $issue) {}

    public function __invoke(User $user, ?User $actor = null): void
    {
        $user->notify(new AccountSetupNotification(($this->issue)($user, $actor)));
    }
}
