<?php

namespace App\Enums;

/**
 * How urgently a tasking must be acted on (Phase 11H), lowest first. The
 * "sent" notification's severity follows it.
 */
enum TaskingPriority: string
{
    case Routine = 'routine';
    case Priority = 'priority';
    case Immediate = 'immediate';

    public function label(): string
    {
        return match ($this) {
            self::Routine => 'Routine',
            self::Priority => 'Priority',
            self::Immediate => 'Immediate',
        };
    }

    public function severity(): NotificationSeverity
    {
        return match ($this) {
            self::Routine => NotificationSeverity::Info,
            self::Priority => NotificationSeverity::Warning,
            self::Immediate => NotificationSeverity::Critical,
        };
    }

    public function rank(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }
}
