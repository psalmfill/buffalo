<?php

namespace App\Enums;

/**
 * The six BUFFALO roles. Each user has exactly one role, and each role is
 * valid at exactly one jurisdiction level (CLAUDE.md section 4).
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case DirectorGeneral = 'director_general';
    case DeputyDirectorGeneral = 'deputy_director_general';
    case StateDirector = 'state_director';
    case DeputyStateDirector = 'deputy_state_director';
    case LgaSecurityChief = 'lga_security_chief';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Administrator',
            self::DirectorGeneral => 'Director General',
            self::DeputyDirectorGeneral => 'Deputy Director General',
            self::StateDirector => 'State Director',
            self::DeputyStateDirector => 'Deputy State Director',
            self::LgaSecurityChief => 'LGA Security Chief',
        };
    }

    /**
     * The only jurisdiction level valid for this role.
     */
    public function jurisdictionLevel(): JurisdictionLevel
    {
        return match ($this) {
            self::SuperAdmin => JurisdictionLevel::System,
            self::DirectorGeneral, self::DeputyDirectorGeneral => JurisdictionLevel::National,
            self::StateDirector, self::DeputyStateDirector => JurisdictionLevel::State,
            self::LgaSecurityChief => JurisdictionLevel::Lga,
        };
    }

    /**
     * Only a DDG may be restricted to geopolitical zones (user_zones).
     */
    public function allowsZones(): bool
    {
        return $this === self::DeputyDirectorGeneral;
    }
}
