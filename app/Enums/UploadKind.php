<?php

namespace App\Enums;

/** Uploads scanned for malware before acceptance (Phase 8B.4). */
enum UploadKind: string
{
    case Evidence = 'evidence';
    case PersonPhoto = 'person_photo';
    case OrganizationLogo = 'organization_logo';
    case SitePhoto = 'site_photo';
    case CommunityCsv = 'community_csv';
    case BoundaryGeoJson = 'boundary_geojson';
    case UserCsv = 'user_csv';

    public function label(): string
    {
        return match ($this) {
            self::Evidence => 'Evidence file',
            self::PersonPhoto => 'Person photo',
            self::OrganizationLogo => 'Organization logo',
            self::SitePhoto => 'Site photo',
            self::CommunityCsv => 'Community import file',
            self::BoundaryGeoJson => 'Boundary GeoJSON file',
            self::UserCsv => 'User import file',
        };
    }
}
