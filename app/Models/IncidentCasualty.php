<?php

namespace App\Models;

use App\Enums\CasualtyGroup;
use App\Enums\CasualtyOutcome;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Casualty counts of one outcome and group (Phase 5A), e.g. 2 abducted civilians.
 *
 * @property CasualtyOutcome $outcome
 * @property CasualtyGroup $group
 * @property int|null $security_agency_id
 * @property int $adult_male
 * @property int $adult_female
 * @property int $child_male
 * @property int $child_female
 * @property int $unknown
 * @property-read SecurityAgency|null $agency
 */
class IncidentCasualty extends IncidentChild
{
    protected $table = 'incident_casualties';

    protected $fillable = ['outcome', 'group', 'adult_male', 'adult_female', 'child_male', 'child_female', 'unknown'];

    protected $hidden = ['id', 'incident_id', 'state_id', 'lga_id', 'person_id', 'security_agency_id', 'agency_formation_id', 'criminal_group_id', 'property_type_id', 'organization_site_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'outcome' => CasualtyOutcome::class,
            'group' => CasualtyGroup::class,
            'adult_male' => 'integer',
            'adult_female' => 'integer',
            'child_male' => 'integer',
            'child_female' => 'integer',
            'unknown' => 'integer',
        ];
    }

    public function total(): int
    {
        return $this->adult_male + $this->adult_female + $this->child_male + $this->child_female + $this->unknown;
    }

    /**
     * @return BelongsTo<SecurityAgency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(SecurityAgency::class, 'security_agency_id');
    }
}
