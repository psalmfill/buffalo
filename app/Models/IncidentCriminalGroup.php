<?php

namespace App\Models;

use App\Enums\IncidentGroupRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A criminal group linked to an incident (Phase 5A).
 *
 * @property int $criminal_group_id
 * @property IncidentGroupRole $role
 * @property string|null $notes
 * @property-read CriminalGroup|null $group
 */
class IncidentCriminalGroup extends IncidentChild
{
    protected $table = 'incident_criminal_groups';

    protected $fillable = ['role', 'notes'];

    protected $hidden = ['id', 'incident_id', 'state_id', 'lga_id', 'person_id', 'security_agency_id', 'agency_formation_id', 'criminal_group_id', 'property_type_id', 'organization_site_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'role' => IncidentGroupRole::class,
        ];
    }

    /**
     * The group, when the viewer can see it (its own scopes apply).
     *
     * @return BelongsTo<CriminalGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CriminalGroup::class, 'criminal_group_id');
    }
}
