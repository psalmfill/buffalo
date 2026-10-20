<?php

namespace App\Models;

use App\Enums\ArrestStatus;
use App\Enums\Classification;
use App\Enums\OccurrencePrecision;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasRichText;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Database\Factories\ArrestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An arrest (Phase 11C): which agency arrested whom, for what and when;
 * standalone or linked to an incident. Scoped by its own jurisdiction and
 * classified; views above `restricted` are access-logged. Reference
 * ARR/<STATE>/<YEAR>/<5 digits> (ArrestReferenceAllocator).
 *
 * Status changes (arrest-wide or per person) are named events with a
 * comment and a status history row (ChangeArrestStatus), never silent.
 *
 * @property int $id
 * @property string $ulid
 * @property string $reference_no
 * @property CarbonImmutable $arrested_at
 * @property OccurrencePrecision $arrested_at_precision
 * @property int $security_agency_id
 * @property int|null $agency_formation_id
 * @property int $state_id
 * @property int $lga_id
 * @property int|null $community_id
 * @property string|null $location_description
 * @property int|null $incident_id
 * @property ArrestStatus $status
 * @property string|null $notes
 * @property Classification $classification
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property float|null $latitude only with withCoordinates()
 * @property float|null $longitude only with withCoordinates()
 * @property-read SecurityAgency $agency
 * @property-read AgencyFormation|null $formation
 * @property-read Lga $lga
 * @property-read State $state
 * @property-read Community|null $community
 * @property-read Incident|null $incident
 * @property-read Collection<int, ArrestPerson> $persons
 */
class Arrest extends Model implements Classified, JurisdictionScoped
{
    use HasClassification;

    /** @use HasFactory<ArrestFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasRichText;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    protected $guarded = ['*'];

    protected $hidden = [
        'id', 'security_agency_id', 'agency_formation_id', 'state_id', 'lga_id', 'community_id', 'incident_id',
        'location', 'search_vector', 'created_by', 'updated_by',
    ];

    protected $attributes = [
        'classification' => 'restricted',
        'status' => 'in_custody',
        'arrested_at_precision' => 'exact',
    ];

    /**
     * Rich text (Phase 11B), sanitised on every save.
     *
     * @return list<string>
     */
    public function richTextFields(): array
    {
        return ['notes'];
    }

    protected function casts(): array
    {
        return [
            'arrested_at' => 'immutable_datetime',
            'arrested_at_precision' => OccurrencePrecision::class,
            'status' => ArrestStatus::class,
            'classification' => Classification::class,
            'created_at' => 'immutable_datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Status changes are named events with their comment; the location is
     * logged by coordinates.
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['location', 'search_vector', 'status'];
    }

    public function logsViews(): bool
    {
        return $this->classificationLevel() !== Classification::Restricted;
    }

    /**
     * @param  Builder<Arrest>  $query
     * @return Builder<Arrest>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('arrests.*');
        }

        return $query
            ->selectRaw('ST_Y(arrests.location::geometry) AS latitude')
            ->selectRaw('ST_X(arrests.location::geometry) AS longitude');
    }

    /**
     * @return BelongsTo<SecurityAgency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(SecurityAgency::class, 'security_agency_id');
    }

    /**
     * The formation, when the viewer can see it (its scopes apply).
     *
     * @return BelongsTo<AgencyFormation, $this>
     */
    public function formation(): BelongsTo
    {
        return $this->belongsTo(AgencyFormation::class, 'agency_formation_id');
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
     * @return BelongsTo<Community, $this>
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * The incident, when the viewer can see it (its scopes apply).
     *
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return HasMany<ArrestPerson, $this>
     */
    public function persons(): HasMany
    {
        return $this->hasMany(ArrestPerson::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
