<?php

namespace App\Enums;

/**
 * Roles a person may hold (person_roles, Phase 3A); a person may hold
 * several. The person-of-interest role is managed only by the POI module
 * (Phase 3B), never through the persons forms.
 */
enum PersonRoleType: string
{
    case PoliticalLeader = 'political_leader';
    case TraditionalRuler = 'traditional_ruler';
    case ReligiousLeader = 'religious_leader';
    case CommunityLeader = 'community_leader';
    case PersonOfInterest = 'person_of_interest';

    public function label(): string
    {
        return match ($this) {
            self::PoliticalLeader => 'Political leader',
            self::TraditionalRuler => 'Traditional ruler',
            self::ReligiousLeader => 'Religious leader',
            self::CommunityLeader => 'Community leader',
            self::PersonOfInterest => 'Person of interest',
        };
    }

    /**
     * Roles that may be set through the persons forms (all but POI).
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::PoliticalLeader, self::TraditionalRuler, self::ReligiousLeader, self::CommunityLeader];
    }
}
