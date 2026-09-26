<?php

namespace App\Enums;

/**
 * Type of a religious institution (Phase 2C).
 */
enum ReligiousInstitutionType: string
{
    case Church = 'church';
    case Mosque = 'mosque';
    case Shrine = 'shrine';
    case IslamiyyaSchool = 'islamiyya_school';
    case MissionSchool = 'mission_school';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Church => 'Church',
            self::Mosque => 'Mosque',
            self::Shrine => 'Shrine',
            self::IslamiyyaSchool => 'Islamiyya school',
            self::MissionSchool => 'Mission school',
            self::Other => 'Other',
        };
    }
}
