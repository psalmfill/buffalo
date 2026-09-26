<?php

namespace App\Models;

use App\Enums\PoiStatus;
use App\Enums\RiskLevel;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Database\Factories\PersonOfInterestFactory;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A person of interest (Phase 3B): one per person.
 *
 * Visibility: jurisdiction (state_id/lga_id, always equal to the person's:
 * composite foreign key) AND the person must be visible: the "person"
 * global scope applies the person's jurisdiction and classification
 * scopes. There is no classification column of its own.
 *
 * Status and risk changes are named audit events with a comment
 * (ChangePoiStatus). Reads of the details are access-logged (context
 * "poi").
 *
 * @property int $id
 * @property string $ulid
 * @property int $person_id
 * @property int $state_id
 * @property int $lga_id
 * @property RiskLevel $risk_level
 * @property PoiStatus $status
 * @property list<string> $offence_categories threat type codes
 * @property string $known_offences
 * @property string|null $modus_operandi
 * @property string|null $last_known_location_description
 * @property CarbonImmutable|null $last_sighted_at
 * @property CarbonImmutable $review_due_at
 * @property CarbonImmutable|null $last_reviewed_at
 * @property int|null $last_reviewed_by
 * @property CarbonImmutable|null $review_reminded_for
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property float|null $latitude only with withCoordinates()
 * @property float|null $longitude only with withCoordinates()
 * @property-read Person $person
 * @property-read Lga $lga
 * @property-read State $state
 * @property-read User|null $reviewer
 */
class PersonOfInterest extends Model implements JurisdictionScoped
{
    /** @use HasFactory<PersonOfInterestFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    protected $table = 'persons_of_interest';

    protected $fillable = ['offence_categories', 'known_offences', 'modus_operandi', 'last_known_location_description', 'last_sighted_at'];

    protected $hidden = ['id', 'person_id', 'state_id', 'lga_id', 'last_known_location', 'last_reviewed_by', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'risk_level' => RiskLevel::class,
            'status' => PoiStatus::class,
            'offence_categories' => 'array',
            'last_sighted_at' => 'datetime',
            'review_due_at' => 'date:Y-m-d',
            'last_reviewed_at' => 'datetime',
            'review_reminded_for' => 'date:Y-m-d',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // Visible only when the person is (jurisdiction AND classification).
        static::addGlobalScope('person', fn (Builder $query) => $query->whereHas('person'));
    }

    /**
     * Binary geography is not useful in the audit log; sightings are logged
     * as a named event.
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['last_known_location'];
    }

    public static function pointFrom(?float $latitude, ?float $longitude): ?Expression
    {
        return Community::pointFrom($latitude, $longitude);
    }

    /**
     * @param  Builder<PersonOfInterest>  $query
     * @return Builder<PersonOfInterest>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('persons_of_interest.*');
        }

        return $query->selectRaw('ST_Y(persons_of_interest.last_known_location::geometry) AS latitude, ST_X(persons_of_interest.last_known_location::geometry) AS longitude');
    }

    /**
     * @param  Builder<PersonOfInterest>  $query
     * @return Builder<PersonOfInterest>
     */
    public function scopeReviewOverdue(Builder $query): Builder
    {
        return $query->where('persons_of_interest.review_due_at', '<', CarbonImmutable::today()->toDateString());
    }

    /**
     * Highest risk first, then most recently sighted.
     *
     * @param  Builder<PersonOfInterest>  $query
     * @return Builder<PersonOfInterest>
     */
    public function scopeByPriority(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE persons_of_interest.risk_level WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END DESC")
            ->orderBy(DB::raw('persons_of_interest.last_sighted_at'), 'desc')
            ->orderByDesc('persons_of_interest.id');
    }

    public function isReviewOverdue(): bool
    {
        return $this->review_due_at->lessThan(CarbonImmutable::today());
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reviewed_by');
    }
}
