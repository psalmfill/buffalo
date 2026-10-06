<?php

namespace App\Enums;

/** Uploads scanned for malware before acceptance (Phase 8B.4). */
enum UploadKind: string
{
    case Evidence = 'evidence';
    case PersonPhoto = 'person_photo';
    case CommunityCsv = 'community_csv';
    case BoundaryGeoJson = 'boundary_geojson';

    public function label(): string
    {
        return match ($this) {
            self::Evidence => 'Evidence file',
            self::PersonPhoto => 'Person photo',
            self::CommunityCsv => 'Community import file',
            self::BoundaryGeoJson => 'Boundary GeoJSON file',
        };
    }
}
