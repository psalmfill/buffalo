<?php

namespace App\Support\ReferenceData;

use App\Models\Denomination;
use App\Models\IncidentType;
use App\Models\OrganizationSector;
use App\Models\PropertyType;
use App\Models\ReferenceModel;
use App\Models\Religion;
use App\Models\SecurityAgency;
use App\Models\ThreatType;
use Illuminate\Database\Eloquent\Builder;

/**
 * The lookup tables, by URL slug (/reference-data/{table}).
 */
enum ReferenceTable: string
{
    case IncidentTypes = 'incident-types';
    case ThreatTypes = 'threat-types';
    case SecurityAgencies = 'security-agencies';
    case Religions = 'religions';
    case Denominations = 'denominations';
    case PropertyTypes = 'property-types';
    case OrganizationSectors = 'organization-sectors';

    /**
     * @return class-string<IncidentType|ThreatType|SecurityAgency|Religion|Denomination|PropertyType|OrganizationSector>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::IncidentTypes => IncidentType::class,
            self::ThreatTypes => ThreatType::class,
            self::SecurityAgencies => SecurityAgency::class,
            self::Religions => Religion::class,
            self::Denominations => Denomination::class,
            self::PropertyTypes => PropertyType::class,
            self::OrganizationSectors => OrganizationSector::class,
        };
    }

    /**
     * @return Builder<IncidentType>|Builder<ThreatType>|Builder<SecurityAgency>|Builder<Religion>|Builder<Denomination>|Builder<PropertyType>|Builder<OrganizationSector>
     */
    public function query(): Builder
    {
        return $this->modelClass()::query();
    }

    public function newModel(): ReferenceModel
    {
        $class = $this->modelClass();

        return new $class;
    }

    public function label(): string
    {
        return match ($this) {
            self::IncidentTypes => 'Incident types',
            self::ThreatTypes => 'Threat types',
            self::SecurityAgencies => 'Security agencies',
            self::Religions => 'Religions',
            self::Denominations => 'Denominations',
            self::PropertyTypes => 'Property types',
            self::OrganizationSectors => 'Organization sectors',
        };
    }

    public function singular(): string
    {
        return match ($this) {
            self::IncidentTypes => 'Incident type',
            self::ThreatTypes => 'Threat type',
            self::SecurityAgencies => 'Security agency',
            self::Religions => 'Religion',
            self::Denominations => 'Denomination',
            self::PropertyTypes => 'Property type',
            self::OrganizationSectors => 'Organization sector',
        };
    }

    public function tableName(): string
    {
        return str_replace('-', '_', $this->value);
    }
}
