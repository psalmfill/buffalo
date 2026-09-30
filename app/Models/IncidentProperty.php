<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Property affected by an incident (Phase 5A), optionally at an organization site.
 *
 * @property int $property_type_id
 * @property string|null $description
 * @property int|null $quantity
 * @property string|null $estimated_value_naira
 * @property int|null $organization_site_id
 * @property-read PropertyType $propertyType
 * @property-read OrganizationSite|null $site
 */
class IncidentProperty extends IncidentChild
{
    protected $table = 'incident_properties';

    protected $fillable = ['description', 'quantity', 'estimated_value_naira'];

    protected $hidden = ['id', 'incident_id', 'state_id', 'lga_id', 'person_id', 'security_agency_id', 'agency_formation_id', 'criminal_group_id', 'property_type_id', 'organization_site_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'estimated_value_naira' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PropertyType, $this>
     */
    public function propertyType(): BelongsTo
    {
        return $this->belongsTo(PropertyType::class);
    }

    /**
     * The site, when the viewer can see it.
     *
     * @return BelongsTo<OrganizationSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(OrganizationSite::class, 'organization_site_id');
    }
}
