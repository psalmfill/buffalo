<?php

namespace App\Enums;

/**
 * Type of criminal group (Phase 3B).
 */
enum CriminalGroupType: string
{
    case Cult = 'cult';
    case BanditGroup = 'bandit_group';
    case KidnapSyndicate = 'kidnap_syndicate';
    case ArmedRobberyGang = 'armed_robbery_gang';
    case PiracyGang = 'piracy_gang';
    case OilTheftNetwork = 'oil_theft_network';
    case TerroristGroup = 'terrorist_group';
    case Militia = 'militia';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cult => 'Cult',
            self::BanditGroup => 'Bandit group',
            self::KidnapSyndicate => 'Kidnap syndicate',
            self::ArmedRobberyGang => 'Armed robbery gang',
            self::PiracyGang => 'Piracy gang',
            self::OilTheftNetwork => 'Oil theft network',
            self::TerroristGroup => 'Terrorist group',
            self::Militia => 'Militia',
            self::Other => 'Other',
        };
    }
}
