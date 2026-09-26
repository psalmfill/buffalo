<?php

namespace App\Enums;

/**
 * Category of vulnerable persons (Phase 2C). Figures are aggregate counts only.
 */
enum VulnerableCategory: string
{
    case MentalHealthCondition = 'mental_health_condition';
    case PhysicalDisability = 'physical_disability';
    case ElderlyWithoutSupport = 'elderly_without_support';
    case UnaccompaniedMinors = 'unaccompanied_minors';
    case InternallyDisplaced = 'internally_displaced';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MentalHealthCondition => 'Mental health condition',
            self::PhysicalDisability => 'Physical disability',
            self::ElderlyWithoutSupport => 'Elderly without support',
            self::UnaccompaniedMinors => 'Unaccompanied minors',
            self::InternallyDisplaced => 'Internally displaced',
            self::Other => 'Other',
        };
    }
}
