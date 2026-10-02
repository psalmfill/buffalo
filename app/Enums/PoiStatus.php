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

    /**
     * "Active" persons of interest (global search, Phase 7B): every status
     * except deceased and cleared.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::AtLarge, self::UnderSurveillance, self::Detained, self::OnTrial, self::Convicted];
    }

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
