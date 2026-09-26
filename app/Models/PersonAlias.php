<?php

namespace App\Models;

use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Another name a person is known by (Phase 3A). search_alias is a
 * generated column (lowercased, unaccented), searched like search_name.
 * Only reached through its person (which is jurisdiction-scoped).
 *
 * @property int $id
 * @property string $ulid
 * @property int $person_id
 * @property string $alias
 * @property string $search_alias
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read Person $person
 */
class PersonAlias extends Model
{
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    protected $fillable = ['alias'];

    protected $hidden = ['id', 'person_id', 'created_by', 'search_alias'];

    /** @return list<string> */
    protected function auditExcept(): array
    {
        return ['search_alias'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
