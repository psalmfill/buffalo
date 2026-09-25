<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorResetNotification extends Notification
{
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
            ->subject('Your '.config('app.name').' two-factor authentication was reset')
            ->greeting("Hello {$notifiable->name},")
            ->line('An administrator has reset two-factor authentication on your account and signed you out everywhere.')
            ->line('You will be asked to set up two-factor authentication again the next time you sign in.')
            ->line('If you did not ask for this, contact your administrator immediately.');
    }
}
