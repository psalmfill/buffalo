<?php

namespace App\Models;

use App\Enums\PoliticalOfficeType;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use App\Support\Jurisdiction\Contracts\LgaVisibilityRule;
use Carbon\CarbonImmutable;
use Database\Factories\PoliticalOfficeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An office a person holds or held (Phase 3B), placed by state and, when
 * the office type calls for it, senatorial district, LGA and ward.
 *
 * Jurisdiction: state-level users see offices in their states. An LGA
 * chief sees (LgaVisibilityRule) offices of their LGA, and offices with no
 * LGA whose senatorial district contains their LGA (a senator of their
 * district). State-wide offices with neither (governor, commissioner) are
 * not visible to LGA chiefs.
 *
 * Current: not ended, or ending today or later.
 *
 * @property int $id
 * @property string $ulid
 * @property int $person_id
 * @property PoliticalOfficeType $office
 * @property string|null $office_title
 * @property string|null $party
 * @property int $state_id
 * @property int|null $senatorial_district_id
 * @property int|null $lga_id
 * @property int|null $ward_id
 * @property string|null $constituency_name
 * @property CarbonImmutable $started_on
 * @property CarbonImmutable|null $ended_on
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property-read Person|null $person null when the person is not visible
 * @property-read State $state
 * @property-read SenatorialDistrict|null $senatorialDistrict
 * @property-read Lga|null $lga
 * @property-read Ward|null $ward
 */
class PoliticalOffice extends Model implements JurisdictionScoped, LgaVisibilityRule
{
    /** @use HasFactory<PoliticalOfficeFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    protected $fillable = ['office', 'office_title', 'party', 'constituency_name', 'started_on', 'ended_on'];

    protected $hidden = ['id', 'person_id', 'state_id', 'senatorial_district_id', 'lga_id', 'ward_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'office' => PoliticalOfficeType::class,
            'started_on' => 'date:Y-m-d',
            'ended_on' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        // An office reveals its holder: visible only when the person is
        // (jurisdiction AND classification).
        static::addGlobalScope('person', fn (Builder $query) => $query->whereHas('person'));
    }

    public function applyLgaVisibility(Builder $query, int $lgaId): void
    {
        $query->where($this->qualifyColumn('lga_id'), $lgaId)
            ->orWhere(fn (Builder $q) => $q
                ->whereNull($this->qualifyColumn('lga_id'))
                ->whereIn($this->qualifyColumn('senatorial_district_id'), Lga::query()->whereKey($lgaId)->select('senatorial_district_id')));
    }

    public function visibleToLga(int $lgaId): bool
    {
        if ($this->lga_id !== null) {
            return $this->lga_id === $lgaId;
        }

        return $this->senatorial_district_id !== null
            && $this->senatorial_district_id === Lga::query()->whereKey($lgaId)->value('senatorial_district_id');
    }

    public function isCurrent(): bool
    {
        return $this->ended_on === null || $this->ended_on->greaterThanOrEqualTo(CarbonImmutable::today());
    }

    /**
     * @param  Builder<PoliticalOffice>  $query
     * @return Builder<PoliticalOffice>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull($this->qualifyColumn('ended_on'))
            ->orWhere($this->qualifyColumn('ended_on'), '>=', CarbonImmutable::today()->toDateString()));
    }

    /**
     * @param  Builder<PoliticalOffice>  $query
     * @return Builder<PoliticalOffice>
     */
    public function scopePast(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('ended_on'), '<', CarbonImmutable::today()->toDateString());
    }

    /** "Senator", "Commissioner for Works", … */
    public function title(): string
    {
        return $this->office_title !== null && $this->office_title !== ''
            ? $this->office_title
            : $this->office->label();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return BelongsTo<SenatorialDistrict, $this>
     */
    public function senatorialDistrict(): BelongsTo
    {
        return $this->belongsTo(SenatorialDistrict::class);
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /**
     * @return BelongsTo<Ward, $this>
     */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }
}
