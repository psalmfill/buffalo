<?php

namespace App\Models;

use App\Models\Concerns\LogsBuffaloActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role a person holds (Phase 3A). Unique per person and role type; a role
 * that no longer applies is deactivated, not deleted, so its history stays.
 * Only reached through its person (which is jurisdiction-scoped).
 *
 * The type is a lookup (person_role_types, Phase 11A.1); its name is
 * written into each activity description, since the history never shows
 * numeric ids.
 *
 * @property int $id
 * @property int $person_id
 * @property int $person_role_type_id
 * @property bool $is_active
 * @property string|null $notes
 * @property int|null $created_by
 * @property-read Person $person
 * @property-read PersonRoleType $type
 */
class PersonRole extends Model
{
    use LogsBuffaloActivity;

    protected $fillable = ['is_active', 'notes'];

    protected $hidden = ['id', 'person_id', 'person_role_type_id', 'created_by'];

    /** The type is always needed to show a role (a tiny lookup table). */
    protected $with = ['type'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        $type = PersonRoleType::query()->whereKey($this->person_role_type_id)->value('name') ?? 'role';

        return match ($eventName) {
            'created' => "Role added: {$type}",
            'updated' => "Role changed: {$type}",
            default => "Role {$eventName}: {$type}",
        };
    }

    /** The role type's code (a PersonRoleType constant). */
    public function typeCode(): string
    {
        return $this->type->code;
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<PersonRoleType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(PersonRoleType::class, 'person_role_type_id');
    }
}
