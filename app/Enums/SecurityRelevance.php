<?php

namespace App\Enums;

/**
 * How security-relevant an organization site is (Phase 4B).
 */
enum SecurityRelevance: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case CriticalInfrastructure = 'critical_infrastructure';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::CriticalInfrastructure => 'Critical infrastructure',
        };
    }

    /** Higher is more relevant (for ordering). */
    public function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::High => 2,
            self::CriticalInfrastructure => 3,
        };
    }
}
