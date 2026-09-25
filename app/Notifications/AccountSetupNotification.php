<?php

namespace App\Notifications;

use App\Models\AccountSetupToken;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a one-time account setup link. Sent synchronously so the plain
 * link is never stored in the queue.
 */
class AccountSetupNotification extends Notification
{
    public function __construct(public readonly string $url) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Set up your '.config('app.name').' account')
            ->greeting("Hello {$notifiable->name},")
            ->line('An account has been created for you. Use the button below to choose your password and set up two-factor authentication.')
            ->action('Set up my account', $this->url)
            ->line('This link can be used once and expires in '.AccountSetupToken::VALID_HOURS.' hours.')
            ->line('If you did not expect this email, contact your administrator and do not use the link.');
    }
}
