<?php

namespace App\Models;

use App\Enums\ArrestStatus;
use App\Models\Concerns\HasJurisdiction;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A status change of an arrest or of one arrested person (Phase 11C).
 * Append-only (trigger); scoped on its own place and visible only while
 * its arrest is.
 *
 * @property int $id
 * @property int $arrest_id
 * @property int|null $arrest_person_id
 * @property ArrestStatus|null $from_status
 * @property ArrestStatus $to_status
 * @property string $comment
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property-read ArrestPerson|null $arrestPerson
 */
class ArrestStatusHistory extends Model implements JurisdictionScoped
{
    use HasJurisdiction;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::addGlobalScope('arrest', fn (Builder $query) => $query->whereHas('arrest'));
    }

    protected function casts(): array
    {
        return [
            'from_status' => ArrestStatus::class,
            'to_status' => ArrestStatus::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Arrest, $this>
     */
    public function arrest(): BelongsTo
    {
        return $this->belongsTo(Arrest::class);
    }

    /**
     * @return BelongsTo<ArrestPerson, $this>
     */
    public function arrestPerson(): BelongsTo
    {
        return $this->belongsTo(ArrestPerson::class);
    }
}
