<?php

namespace App\Models;

use App\Enums\PersonInvolvement;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person involved in an incident (Phase 5A): a person on record (linked with `linkToIncident`) or an unidentified person described in words. The person's name is shown only to viewers who can see that person.
 *
 * @property int|null $person_id
 * @property string|null $unidentified_description
 * @property PersonInvolvement $involvement
 * @property string|null $notes
 * @property-read Person|null $person
 */
class IncidentPerson extends IncidentChild
{
    protected $table = 'incident_persons';

    protected $fillable = ['unidentified_description', 'involvement', 'notes'];

    protected $hidden = ['id', 'incident_id', 'state_id', 'lga_id', 'person_id', 'security_agency_id', 'agency_formation_id', 'criminal_group_id', 'property_type_id', 'organization_site_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'involvement' => PersonInvolvement::class,
        ];
    }

    /**
     * The person, when the viewer can see them (the person's scopes apply).
     *
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
