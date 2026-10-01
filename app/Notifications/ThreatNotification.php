<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\ThreatReport;
use App\Models\User;

/**
 * Threat report notifications (Phase 6B). The payload is the reference,
 * threat type, urgency, LGA and a link (checked when opened): never the
 * description, target or source.
 */
final class ThreatNotification extends BuffaloNotification
{
    private const TITLES = [
        'created_immediate' => 'Immediate threat reported',
        'created_priority' => 'Priority threat reported',
        'escalated' => 'Threat raised to immediate or confirmed',
        'materialised' => 'Threat materialised',
        'expiring' => 'Threat report expiring within 24 hours',
    ];

    public function __construct(
        public readonly string $event,
        public readonly string $threatUlid,
        public readonly string $reference,
        public readonly string $threatType,
        public readonly string $urgency,
        public readonly string $lga,
        public readonly NotificationSeverity $level,
    ) {}

    public static function about(string $event, ThreatReport $threat, NotificationSeverity $level): self
    {
        return new self($event, $threat->ulid, $threat->reference_no, $threat->threatType->name, $threat->urgency->label(), $threat->lga->name, $level);
    }

    public function type(): string
    {
        return 'threat_'.$this->event;
    }

    public function title(User $notifiable): string
    {
        return self::TITLES[$this->event] ?? 'Threat report update';
    }

    public function body(User $notifiable): string
    {
        $what = "{$this->reference} — {$this->threatType} ({$this->urgency}), {$this->lga} LGA";

        return match ($this->event) {
            'expiring' => "{$what} expires within 24 hours. Extend it if it is still relevant.",
            'materialised' => "{$what} has materialised as an incident.",
            default => $what,
        };
    }

    public function url(User $notifiable): string
    {
        return '/threats/'.$this->threatUlid;
    }

    public function severity(): NotificationSeverity
    {
        return $this->level;
    }
}
