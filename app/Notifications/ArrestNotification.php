<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\Arrest;
use App\Models\User;

/**
 * Arrest notifications (Phase 11C). The payload is the reference, the LGA
 * and a link (checked when opened): never names or offences.
 */
final class ArrestNotification extends BuffaloNotification
{
    private const TITLES = [
        'poi_arrested' => 'Person of interest arrested',
        'escalated_incident' => 'Arrest on an escalated incident',
    ];

    public function __construct(
        public readonly string $event,
        public readonly string $arrestUlid,
        public readonly string $reference,
        public readonly string $lga,
    ) {}

    public static function about(string $event, Arrest $arrest): self
    {
        return new self($event, $arrest->ulid, $arrest->reference_no, $arrest->lga->name);
    }

    public function type(): string
    {
        return 'arrest_'.$this->event;
    }

    public function title(User $notifiable): string
    {
        return self::TITLES[$this->event] ?? 'Arrest recorded';
    }

    public function body(User $notifiable): string
    {
        return match ($this->event) {
            'poi_arrested' => "{$this->reference}, {$this->lga} LGA: a person of interest from your state was arrested.",
            'escalated_incident' => "{$this->reference}, {$this->lga} LGA: an arrest was recorded on an incident escalated to national level.",
            default => "{$this->reference}, {$this->lga} LGA",
        };
    }

    public function url(User $notifiable): string
    {
        return '/arrests/'.$this->arrestUlid;
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Warning;
    }
}
