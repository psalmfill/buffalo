<?php

namespace App\Models;

use App\Enums\ArrestStatus;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person arrested (Phase 11C): a person on record (linked with
 * `linkToArrest`: own jurisdiction or an active grant) or an unidentified
 * person described in words. The row carries its own state_id/lga_id,
 * kept equal to the arrest's by a cascading composite FK, and is scoped on
 * them (access rule 9), AND visible only while its arrest is (adding the
 * arrest's classification). The person's name shows only to viewers who
 * can see that person.
 *
 * @property int $id
 * @property string $ulid
 * @property int $arrest_id
 * @property int $state_id
 * @property int $lga_id
 * @property int|null $person_id
 * @property string|null $unidentified_description
 * @property ArrestStatus $status
 * @property string|null $notes
 * @property int|null $created_by
 * @property-read Arrest $arrest
 * @property-read Person|null $person
 * @property-read Collection<int, ArrestPersonOffence> $offences
 */
class ArrestPerson extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    protected $table = 'arrest_persons';

    protected $guarded = ['*'];

    protected $hidden = ['id', 'arrest_id', 'state_id', 'lga_id', 'person_id', 'created_by', 'updated_by'];

    protected $attributes = ['status' => 'in_custody'];

    protected static function booted(): void
    {
        static::addGlobalScope('arrest', fn (Builder $query) => $query->whereHas('arrest'));
    }

    protected function casts(): array
    {
        return ['status' => ArrestStatus::class];
    }

    /**
     * Status changes are named events (ChangeArrestStatus).
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['status'];
    }

    /**
     * @return BelongsTo<Arrest, $this>
     */
    public function arrest(): BelongsTo
    {
        return $this->belongsTo(Arrest::class);
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

    /**
     * @return HasMany<ArrestPersonOffence, $this>
     */
    public function offences(): HasMany
    {
        return $this->hasMany(ArrestPersonOffence::class);
    }
}
