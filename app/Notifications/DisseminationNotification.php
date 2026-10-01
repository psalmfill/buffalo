<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * Dissemination notifications (Phase 7A). The payload is the record's
 * reference or name, its kind, the source state (and the place concerned
 * for acknowledgements) and a link checked when opened: never the
 * description, area or reason.
 */
final class DisseminationNotification extends BuffaloNotification
{
    private const TITLES = [
        'disseminated' => 'Shared with you',
        'reminder' => 'Shared record not yet acknowledged',
        'acknowledged' => 'Dissemination acknowledged',
        'revoked' => 'Sharing revoked',
        'expired' => 'Sharing ended',
        'requested' => 'Dissemination requested',
        'declined' => 'Dissemination request declined',
        'gap' => 'Cross-border record not disseminated',
    ];

    public function __construct(
        public readonly string $event,
        public readonly string $path,
        public readonly string $label,
        public readonly string $kind,
        public readonly string $source,
        public readonly NotificationSeverity $level,
        public readonly ?string $place = null,
    ) {}

    public function type(): string
    {
        return 'dissemination_'.$this->event;
    }

    public function title(User $notifiable): string
    {
        return self::TITLES[$this->event] ?? 'Dissemination update';
    }

    public function body(User $notifiable): string
    {
        $what = "{$this->kind} {$this->label} ({$this->source})";

        return match ($this->event) {
            'disseminated' => "{$what} has been shared with you. Acknowledge it once read.",
            'reminder' => "{$what} was shared with you and has not been acknowledged.",
            'acknowledged' => "{$what} was acknowledged by {$this->place}.",
            'revoked' => "{$what} is no longer shared with you.",
            'expired' => "Sharing of {$what} has ended.",
            'requested' => "{$what}: an LGA chief asks you to share it with other places.",
            'declined' => "Your request to share {$what} was declined.",
            'gap' => "{$what} reaches places outside its jurisdiction that it has not been shared with.",
            default => $what,
        };
    }

    public function url(User $notifiable): string
    {
        return in_array($this->event, ['revoked', 'expired'], true) ? '/disseminations' : $this->path;
    }

    public function severity(): NotificationSeverity
    {
        return $this->level;
    }
}
