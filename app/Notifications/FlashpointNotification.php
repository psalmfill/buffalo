<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\Flashpoint;
use App\Models\User;

/**
 * Flashpoint notifications (Phase 6A). The payload names the kind, the
 * threat type and the LGA, never the flashpoint's name or description
 * (which may be sensitive), plus a link checked when opened. A near-
 * flashpoint notice adds the incident's reference.
 */
final class FlashpointNotification extends BuffaloNotification
{
    private const TITLES = [
        'incident_nearby' => 'Incident reported inside a flashpoint area',
        'reassessment_due' => 'Blackspot needs reassessment',
        'review_due' => 'Flashpoint review due',
    ];

    public function __construct(
        public readonly string $event,
        public readonly string $flashpointUlid,
        public readonly string $what,
        public readonly string $lga,
        public readonly ?string $incidentReference = null,
        public readonly NotificationSeverity $level = NotificationSeverity::Warning,
    ) {}

    public static function about(string $event, Flashpoint $flashpoint, ?string $incidentReference = null, NotificationSeverity $level = NotificationSeverity::Warning): self
    {
        return new self(
            $event,
            $flashpoint->ulid,
            "{$flashpoint->kind->label()} ({$flashpoint->threatType->name})",
            $flashpoint->lga->name,
            $incidentReference,
            $level,
        );
    }

    public function type(): string
    {
        return 'flashpoint_'.$this->event;
    }

    public function title(User $notifiable): string
    {
        return self::TITLES[$this->event] ?? 'Flashpoint update';
    }

    public function body(User $notifiable): string
    {
        $where = "{$this->what}, {$this->lga} LGA";

        return match ($this->event) {
            'incident_nearby' => "Incident {$this->incidentReference} was reported inside the area of a {$where}.",
            'reassessment_due' => "Incidents keep occurring at a {$where}. Reassess its concern level.",
            'review_due' => "A {$where} is due for review.",
            default => $where,
        };
    }

    public function url(User $notifiable): string
    {
        return '/flashpoints/'.$this->flashpointUlid;
    }

    public function severity(): NotificationSeverity
    {
        return $this->level;
    }
}
