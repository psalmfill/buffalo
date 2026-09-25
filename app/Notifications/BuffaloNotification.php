<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;
use App\Support\Auth\MailConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * Base class for every BUFFALO notification.
 *
 * - Always delivered to the database and broadcast (Reverb, private channel
 *   `users.{ulid}`); by mail only when BUFFALO_MAIL_ENABLED=true AND the
 *   notification allows it (allowsMail()).
 * - Always queued, and encrypted while queued.
 *
 * PAYLOAD RULE: database and broadcast payloads (type, title, body, url,
 * severity, created_at) must NEVER contain sensitive content: no names of
 * persons of interest, no incident details, no classified text, no tokens
 * or links carrying secrets. They say what happened and link to the record
 * (an internal path), which is then access-checked when opened. Payloads
 * are stored unencrypted in the notifications table and pushed over the
 * WebSocket.
 */
abstract class BuffaloNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Horizon defaults to one attempt; a worker that dies mid-job would then
     * drop the notification silently. Security alerts must be retried.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** Machine-readable type, e.g. "account_locked". */
    abstract public function type(): string;

    abstract public function title(User $notifiable): string;

    abstract public function body(User $notifiable): string;

    /** Internal path (e.g. "/users/01h..."), or null. Never a full URL. */
    public function url(User $notifiable): ?string
    {
        return null;
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Info;
    }

    /** Whether this notification may also be sent by email. */
    public function allowsMail(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if ($this->allowsMail() && MailConfiguration::isConfigured()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * @return array{type: string, title: string, body: string, url: string|null, severity: string, created_at: string}
     */
    public function toArray(User $notifiable): array
    {
        $url = $this->url($notifiable);

        if ($url !== null && (! str_starts_with($url, '/') || str_starts_with($url, '//'))) {
            throw new InvalidArgumentException('Notification URLs must be internal paths.');
        }

        return [
            'type' => $this->type(),
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'url' => $url,
            'severity' => $this->severity()->value,
            'created_at' => now()->toIso8601String(),
        ];
    }

    public function toBroadcast(User $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function broadcastType(): string
    {
        return 'buffalo.notification';
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = $this->url($notifiable);

        return (new MailMessage)
            ->subject($this->title($notifiable))
            ->line($this->body($notifiable))
            ->when($url !== null, fn (MailMessage $mail) => $mail->action('Open '.config('app.name'), url((string) $url)));
    }
}
