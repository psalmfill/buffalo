<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * Incident workflow notifications (Phase 5C). The payload is the reference
 * number, the incident type, the LGA and a link (checked when opened):
 * never persons' names, casualties or descriptions.
 */
final class IncidentNotification extends BuffaloNotification
{
    private const TITLES = [
        'submitted' => 'Incident reported',
        'submitted_critical' => 'Critical incident reported (escalated)',
        'returned' => 'Incident returned to you',
        'verified' => 'Incident verified',
        'resolved' => 'Incident resolved',
        'closed' => 'Incident closed',
        'invalidated' => 'Incident marked invalid',
        'escalated' => 'Incident escalated to national level',
        'acknowledged' => 'Escalation acknowledged',
        'update_added' => 'New update on an incident',
        'escalated_update' => 'New update on an escalated incident',
        'verification_overdue' => 'Incident awaiting verification',
        'verification_overdue_national' => 'Incident still unverified',
        'consolidated' => 'Information added to your incident',
    ];

    public function __construct(
        public readonly string $event,
        public readonly string $incidentUlid,
        public readonly string $reference,
        public readonly string $incidentType,
        public readonly string $lga,
        public readonly NotificationSeverity $level = NotificationSeverity::Info,
    ) {}

    public function type(): string
    {
        return 'incident_'.$this->event;
    }

    public function title(User $notifiable): string
    {
        return self::TITLES[$this->event] ?? 'Incident update';
    }

    public function body(User $notifiable): string
    {
        $what = "{$this->reference} — {$this->incidentType}, {$this->lga} LGA";

        return match ($this->event) {
            'verification_overdue' => "{$what} is past its verification deadline.",
            'verification_overdue_national' => "{$what} is still unverified at twice its verification deadline.",
            'acknowledged' => "National level acknowledged the escalation of {$what}.",
            'consolidated' => "Information from another report was added to your incident {$what}.",
            default => $what,
        };
    }

    public function url(User $notifiable): string
    {
        return '/incidents/'.$this->incidentUlid;
    }

    public function severity(): NotificationSeverity
    {
        return $this->level;
    }
}
