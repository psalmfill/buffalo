<?php

namespace App\Enums;

/**
 * Kind of organization site (Phase 4B).
 */
enum SiteType: string
{
    case HeadOffice = 'head_office';
    case RegionalOffice = 'regional_office';
    case Branch = 'branch';
    case Facility = 'facility';
    case Plant = 'plant';
    case Terminal = 'terminal';
    case Depot = 'depot';
    case FieldCamp = 'field_camp';
    case Warehouse = 'warehouse';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::HeadOffice => 'Head office',
            self::RegionalOffice => 'Regional office',
            self::Branch => 'Branch',
            self::Facility => 'Facility',
            self::Plant => 'Plant',
            self::Terminal => 'Terminal',
            self::Depot => 'Depot',
            self::FieldCamp => 'Field camp',
            self::Warehouse => 'Warehouse',
            self::Other => 'Other',
        };
    }
}
