<?php

namespace App\Enums;

/**
 * Type of organization in the national directory (Phase 4B).
 */
enum OrganizationType: string
{
    case Company = 'company';
    case Ngo = 'ngo';
    case GovernmentAgency = 'government_agency';
    case InternationalOrganization = 'international_organization';
    case AssociationUnion = 'association_union';
    case EducationalInstitution = 'educational_institution';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Company',
            self::Ngo => 'NGO',
            self::GovernmentAgency => 'Government agency',
            self::InternationalOrganization => 'International organization',
            self::AssociationUnion => 'Association or union',
            self::EducationalInstitution => 'Educational institution',
            self::Other => 'Other',
        };
    }
}
