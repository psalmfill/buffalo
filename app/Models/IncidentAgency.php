<?php

namespace App\Models;

use App\Enums\IncidentAgencyRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A security agency's response to an incident (Phase 5A), optionally a specific formation.
 *
 * @property int $security_agency_id
 * @property int|null $agency_formation_id
 * @property IncidentAgencyRole $role
 * @property CarbonImmutable|null $responded_at
 * @property string|null $notes
 * @property-read SecurityAgency $agency
 * @property-read AgencyFormation|null $formation
 */
class IncidentAgency extends IncidentChild
{
    protected $table = 'incident_agencies';

    protected $fillable = ['role', 'responded_at', 'notes'];

    protected $hidden = ['id', 'incident_id', 'state_id', 'lga_id', 'person_id', 'security_agency_id', 'agency_formation_id', 'criminal_group_id', 'property_type_id', 'organization_site_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'role' => IncidentAgencyRole::class,
            'responded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SecurityAgency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(SecurityAgency::class, 'security_agency_id');
    }

    /**
     * The formation, when the viewer can see it.
     *
     * @return BelongsTo<AgencyFormation, $this>
     */
    public function formation(): BelongsTo
    {
        return $this->belongsTo(AgencyFormation::class, 'agency_formation_id');
    }
}
