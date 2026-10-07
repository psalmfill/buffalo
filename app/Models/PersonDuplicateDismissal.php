<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * "Not a duplicate" (Phase 10A.2): the pair never reappears as a
 * candidate. Stored with the lower person ID first. Reached only through
 * the duplicate finder and DismissDuplicatePair, which authorise both
 * persons first; never listed on its own.
 *
 * @property int $id
 * @property int $person_a_id
 * @property int $person_b_id
 * @property int $dismissed_by
 * @property string $reason
 * @property CarbonImmutable $created_at
 */
class PersonDuplicateDismissal extends Model
{
    public const UPDATED_AT = null;

    protected $hidden = ['id', 'person_a_id', 'person_b_id', 'dismissed_by'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
