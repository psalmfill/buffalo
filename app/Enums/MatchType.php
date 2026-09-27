<?php

namespace App\Enums;

/**
 * Strength of a person match (Phase 3C). Only exact and strong matches are
 * ever shown across jurisdictions.
 */
enum MatchType: string
{
    case Exact = 'exact';
    case Strong = 'strong';
    case Possible = 'possible';

    /** What a hit says about its strength (never why). */
    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Record found',
            self::Strong => 'Likely match',
            self::Possible => 'Possible match',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Exact => 3,
            self::Strong => 2,
            self::Possible => 1,
        };
    }

    public function showableAcrossJurisdictions(): bool
    {
        return $this !== self::Possible;
    }
}
