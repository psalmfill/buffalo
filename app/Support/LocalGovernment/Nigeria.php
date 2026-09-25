<?php

namespace App\Support\LocalGovernment;

/**
 * Nigeria's bounding box (matches resources/js/components/map/nigeria.ts
 * and the map tile extract). Coordinates outside it are rejected.
 */
final class Nigeria
{
    public const MIN_LATITUDE = 4.2;

    public const MAX_LATITUDE = 13.9;

    public const MIN_LONGITUDE = 2.6;

    public const MAX_LONGITUDE = 14.7;

    public static function contains(float $latitude, float $longitude): bool
    {
        return $latitude >= self::MIN_LATITUDE && $latitude <= self::MAX_LATITUDE
            && $longitude >= self::MIN_LONGITUDE && $longitude <= self::MAX_LONGITUDE;
    }
}
