<?php

namespace App\Models;

use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An LGA where a criminal group operates (Phase 3B). Its place describes
 * the group; it is not the record's jurisdiction. Visibility comes only
 * from the group (the "group" global scope applies the group's
 * jurisdiction and classification scopes): listing an LGA here never
 * grants anyone in that LGA visibility of the group.
 *
 * @property int $id
 * @property string $ulid
 * @property int $criminal_group_id
 * @property int $state_id
 * @property int $lga_id
 * @property string|null $notes
 * @property int|null $created_by
 * @property-read CriminalGroup $group
 * @property-read Lga $lga
 * @property-read State $state
 */
class CriminalGroupArea extends Model
{
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    protected $fillable = ['notes'];

    protected $hidden = ['id', 'criminal_group_id', 'state_id', 'lga_id', 'created_by'];

    protected static function booted(): void
    {
        static::addGlobalScope('group', fn (Builder $query) => $query->whereHas('group'));
    }

    /**
     * @return BelongsTo<CriminalGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CriminalGroup::class, 'criminal_group_id');
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }
}
