<?php

namespace App\Enums;

/**
 * Category of a community security note (Phase 2C).
 */
enum SecurityNoteCategory: string
{
    case LandDispute = 'land_dispute';
    case ChieftaincyDispute = 'chieftaincy_dispute';
    case CommunalTension = 'communal_tension';
    case FarmerHerder = 'farmer_herder';
    case YouthRestiveness = 'youth_restiveness';
    case CultActivity = 'cult_activity';
    case PoliticalTension = 'political_tension';
    case ReligiousTension = 'religious_tension';
    case BoundaryDispute = 'boundary_dispute';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LandDispute => 'Land dispute',
            self::ChieftaincyDispute => 'Chieftaincy dispute',
            self::CommunalTension => 'Communal tension',
            self::FarmerHerder => 'Farmer-herder',
            self::YouthRestiveness => 'Youth restiveness',
            self::CultActivity => 'Cult activity',
            self::PoliticalTension => 'Political tension',
            self::ReligiousTension => 'Religious tension',
            self::BoundaryDispute => 'Boundary dispute',
            self::Other => 'Other',
        };
    }
}
