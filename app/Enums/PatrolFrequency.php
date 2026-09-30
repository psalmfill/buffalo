<?php

namespace App\Enums;

/**
 * How often a deployment patrols (Phase 4A coverage reports).
 */
enum PatrolFrequency: string
{
    case Continuous = 'continuous';
    case Daily = 'daily';
    case SeveralWeekly = 'several_weekly';
    case Weekly = 'weekly';
    case Occasional = 'occasional';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Continuous => 'Continuous',
            self::Daily => 'Daily',
            self::SeveralWeekly => 'Several times a week',
            self::Weekly => 'Weekly',
            self::Occasional => 'Occasional',
            self::None => 'None',
        };
    }
}
