<?php

namespace App\Enums;

/**
 * How a criminal group appears in a threat report (Phase 6B).
 */
enum ThreatGroupRole: string
{
    case Suspected = 'suspected';
    case NamedInReport = 'named_in_report';

    public function label(): string
    {
        return match ($this) {
            self::Suspected => 'Suspected',
            self::NamedInReport => 'Named in report',
        };
    }
}
