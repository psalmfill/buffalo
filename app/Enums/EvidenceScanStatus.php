<?php

namespace App\Enums;

/**
 * Malware scan state of an evidence file (Phase 8B.4). Only `clean` files
 * are ever served; `quarantined` files wait for a scan; `infected` files
 * are moved to the quarantine disk and never served.
 */
enum EvidenceScanStatus: string
{
    case Clean = 'clean';
    case Quarantined = 'quarantined';
    case Infected = 'infected';

    public function label(): string
    {
        return match ($this) {
            self::Clean => 'Scanned, clean',
            self::Quarantined => 'In quarantine, awaiting malware scan',
            self::Infected => 'Malware found, quarantined',
        };
    }
}
