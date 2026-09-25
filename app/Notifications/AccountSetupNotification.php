<?php

namespace App\Notifications;

use App\Models\AccountSetupToken;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Emails a one-time account setup link. The link appears only in the email
 * (encrypted while queued); the database/broadcast payload never contains
 * it.
 */
class AccountSetupNotification extends BuffaloNotification
{
    public function __construct(public readonly string $setupUrl) {}

    public function type(): string
    {
        return 'account_setup';
    }

    public function title(User $notifiable): string
    {
        return 'Account setup link sent';
    }

    public function body(User $notifiable): string
    {
        return 'An account setup link was emailed to you.';
    }

    public function allowsMail(): bool
    {
        return true;
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Set up your '.config('app.name').' account')
            ->greeting("Hello {$notifiable->name},")
            ->line('An account has been created for you. Use the button below to choose your password and set up two-factor authentication.')
            ->action('Set up my account', $this->setupUrl)
            ->line('This link can be used once and expires in '.AccountSetupToken::VALID_HOURS.' hours.')
            ->line('If you did not expect this email, contact your administrator and do not use the link.');
    }
}
