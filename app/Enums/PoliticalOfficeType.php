<?php

namespace App\Enums;

/**
 * A political or administrative office (political_offices, Phase 3B).
 */
enum PoliticalOfficeType: string
{
    case Governor = 'governor';
    case DeputyGovernor = 'deputy_governor';
    case Senator = 'senator';
    case HouseOfRepsMember = 'house_of_reps_member';
    case StateAssemblyMember = 'state_assembly_member';
    case LgaChairman = 'lga_chairman';
    case LgaViceChairman = 'lga_vice_chairman';
    case Councillor = 'councillor';
    case Commissioner = 'commissioner';
    case SpecialAdviser = 'special_adviser';
    case PartyOfficial = 'party_official';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Governor => 'Governor',
            self::DeputyGovernor => 'Deputy governor',
            self::Senator => 'Senator',
            self::HouseOfRepsMember => 'House of Representatives member',
            self::StateAssemblyMember => 'State House of Assembly member',
            self::LgaChairman => 'LGA chairman',
            self::LgaViceChairman => 'LGA vice chairman',
            self::Councillor => 'Councillor',
            self::Commissioner => 'Commissioner',
            self::SpecialAdviser => 'Special adviser',
            self::PartyOfficial => 'Party official',
            self::Other => 'Other',
        };
    }

    /** A senatorial district is required (and fixes the seat). */
    public function requiresDistrict(): bool
    {
        return $this === self::Senator;
    }

    /** An LGA is required (and fixes the seat). */
    public function requiresLga(): bool
    {
        return in_array($this, [self::LgaChairman, self::LgaViceChairman, self::Councillor], true);
    }

    public function requiresWard(): bool
    {
        return $this === self::Councillor;
    }

    /**
     * One current holder per seat: per state (governor, deputy), per
     * district (senator), per LGA (chairman, vice) or per ward (councillor).
     * Overlaps are warned about, never refused: records may be imperfect.
     */
    public function isSingleSeat(): bool
    {
        return in_array($this, [self::Governor, self::DeputyGovernor, self::Senator, self::LgaChairman, self::LgaViceChairman, self::Councillor], true);
    }
}
