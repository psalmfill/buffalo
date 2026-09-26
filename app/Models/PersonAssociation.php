<?php

namespace App\Models;

use App\Enums\AssociationRelationship;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An association between two persons (Phase 3A), stored once per pair
 * (whichever way round) and shown from both sides.
 *
 * The row reads "associated person is the person's <relationship>"; from
 * the associated person's side the relationship is inverted
 * (relationshipFor()).
 *
 * @property int $id
 * @property string $ulid
 * @property int $person_id
 * @property int $associated_person_id
 * @property AssociationRelationship $relationship
 * @property string|null $notes
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read Person|null $person null when not visible to the current user
 * @property-read Person|null $associatedPerson null when not visible to the current user
 */
class PersonAssociation extends Model
{
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    protected $fillable = ['relationship', 'notes'];

    protected $hidden = ['id', 'person_id', 'associated_person_id', 'created_by'];

    protected function casts(): array
    {
        return ['relationship' => AssociationRelationship::class];
    }

    /** The other person of the pair, seen from $person. */
    public function otherPersonId(Person $person): int
    {
        return $this->person_id === $person->id ? $this->associated_person_id : $this->person_id;
    }

    /** The relationship of the other person to $person. */
    public function relationshipFor(Person $person): AssociationRelationship
    {
        return $this->person_id === $person->id ? $this->relationship : $this->relationship->inverse();
    }

    public function involves(Person $person): bool
    {
        return $this->person_id === $person->id || $this->associated_person_id === $person->id;
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function associatedPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'associated_person_id');
    }
}
