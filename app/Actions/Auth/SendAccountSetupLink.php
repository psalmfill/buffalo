<?php

namespace App\Actions\Auth;

use App\Enums\SetupLinkDelivery;
use App\Models\User;
use App\Notifications\AccountSetupNotification;
use App\Support\Auth\MailConfiguration;
use Illuminate\Validation\ValidationException;

/**
 * Email delivery of a setup link: issues a new link and mails it to the
 * user. The URL is not returned to the administrator.
 */
final class SendAccountSetupLink
{
    public function __construct(private readonly IssueAccountSetupLink $issue) {}

    public function __invoke(User $user, ?User $actor = null): void
    {
        if (! MailConfiguration::isConfigured()) {
            throw ValidationException::withMessages(['delivery' => 'Email is not enabled in this deployment. Show the link once and hand it over instead.']);
        }

        $user->notify(new AccountSetupNotification(($this->issue)($user, $actor, SetupLinkDelivery::Email)));
    }
}
