<?php

namespace App\Enums;

/**
 * Who the people counted in a casualty row are (Phase 5A).
 */
enum CasualtyGroup: string
{
    case Civilian = 'civilian';
    case SecurityPersonnel = 'security_personnel';
    case Suspect = 'suspect';

    public function label(): string
    {
        return match ($this) {
            self::Civilian => 'Civilians',
            self::SecurityPersonnel => 'Security personnel',
            self::Suspect => 'Suspects',
        };
    }
}
