<?php

namespace App\Enums;

/**
 * How quickly a threat report must be acted on (Phase 6B), lowest first.
 */
enum ThreatUrgency: string
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

    /** Shown in the explanation panel on the form. */
    public function help(): string
    {
        return match ($this) {
            self::Routine => 'No sign of imminent action; reviewed in the daily digest.',
            self::Priority => 'Could materialise within days; the state and LGA leads are told at once.',
            self::Immediate => 'Could materialise within hours; national level is alerted at once.',
        };
    }

    public function rank(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }
}
