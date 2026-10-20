<?php

namespace App\Models;

use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An offence an arrested person is held for (Phase 11C). Scoped on its
 * own place (kept equal to the arrest's) and visible only while its
 * arrested person is.
 *
 * @property int $id
 * @property int $arrest_person_id
 * @property int $state_id
 * @property int $lga_id
 * @property int $offence_type_id
 * @property string|null $details
 * @property-read ArrestPerson $arrestPerson
 * @property-read OffenceType $offenceType
 */
class ArrestPersonOffence extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use LogsBuffaloActivity;
    use SoftDeletes;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'arrest_person_id', 'state_id', 'lga_id', 'offence_type_id', 'created_by'];

    protected static function booted(): void
    {
        static::addGlobalScope('arrest_person', fn (Builder $query) => $query->whereHas('arrestPerson'));
    }

    /**
     * @return BelongsTo<ArrestPerson, $this>
     */
    public function arrestPerson(): BelongsTo
    {
        return $this->belongsTo(ArrestPerson::class);
    }

    /**
     * @return BelongsTo<OffenceType, $this>
     */
    public function offenceType(): BelongsTo
    {
        return $this->belongsTo(OffenceType::class);
    }
}
