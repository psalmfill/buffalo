<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person's membership of a criminal group (Phase 3B). Visible only when
 * the group is (the "group" global scope); lists also require the person
 * to be visible (withVisiblePerson()).
 *
 * @property int $id
 * @property string $ulid
 * @property int $criminal_group_id
 * @property int $person_id
 * @property string|null $role_in_group
 * @property MembershipStatus $membership_status
 * @property CarbonImmutable|null $since
 * @property string|null $notes
 * @property int|null $created_by
 * @property-read CriminalGroup $group
 * @property-read Person|null $person null when the person is not visible
 */
class CriminalGroupMember extends Model
{
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    protected $fillable = ['role_in_group', 'membership_status', 'since', 'notes'];

    protected $hidden = ['id', 'criminal_group_id', 'person_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'membership_status' => MembershipStatus::class,
            'since' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('group', fn (Builder $query) => $query->whereHas('group'));
    }

    /**
     * Only memberships whose person the current user can see.
     *
     * @param  Builder<CriminalGroupMember>  $query
     * @return Builder<CriminalGroupMember>
     */
    public function scopeWithVisiblePerson(Builder $query): Builder
    {
        return $query->whereHas('person');
    }

    /**
     * @return BelongsTo<CriminalGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CriminalGroup::class, 'criminal_group_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
