<?php

namespace App\Enums;

/**
 * How credible a threat report is judged to be (Phase 6B), lowest first.
 */
enum ThreatCredibility: string
{
    case Unconfirmed = 'unconfirmed';
    case Possible = 'possible';
    case Probable = 'probable';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Unconfirmed => 'Unconfirmed',
            self::Possible => 'Possible',
            self::Probable => 'Probable',
            self::Confirmed => 'Confirmed',
        };
    }

    /** Shown in the explanation panel on the form. */
    public function help(): string
    {
        return match ($this) {
            self::Unconfirmed => 'A single, uncorroborated report; source reliability unknown.',
            self::Possible => 'Plausible and consistent with known activity, but not corroborated.',
            self::Probable => 'Corroborated by a second source or by observed preparations.',
            self::Confirmed => 'Confirmed by several reliable sources or direct observation.',
        };
    }

    public function rank(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }
}
