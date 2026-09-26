<?php

namespace App\Enums;

/**
 * Status of a person of interest (Phase 3B).
 */
enum PoiStatus: string
{
    case AtLarge = 'at_large';
    case UnderSurveillance = 'under_surveillance';
    case Detained = 'detained';
    case OnTrial = 'on_trial';
    case Convicted = 'convicted';
    case Deceased = 'deceased';
    case Cleared = 'cleared';

    public function label(): string
    {
        return match ($this) {
            self::AtLarge => 'At large',
            self::UnderSurveillance => 'Under surveillance',
            self::Detained => 'Detained',
            self::OnTrial => 'On trial',
            self::Convicted => 'Convicted',
            self::Deceased => 'Deceased',
            self::Cleared => 'Cleared',
        };
    }
}
