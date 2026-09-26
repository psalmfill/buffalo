<?php

namespace App\Models;

use App\Enums\PersonRoleType;
use App\Models\Concerns\LogsBuffaloActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role a person holds (Phase 3A). Unique per person and role; a role that
 * no longer applies is deactivated, not deleted, so its history stays.
 * Only reached through its person (which is jurisdiction-scoped).
 *
 * @property int $id
 * @property int $person_id
 * @property PersonRoleType $role
 * @property bool $is_active
 * @property string|null $notes
 * @property int|null $created_by
 * @property-read Person $person
 */
class PersonRole extends Model
{
    use LogsBuffaloActivity;

    protected $fillable = ['role', 'is_active', 'notes'];

    protected $hidden = ['id', 'person_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'role' => PersonRoleType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
