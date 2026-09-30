<?php

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\Classification;
use App\Enums\IncidentStatus;
use App\Enums\OccurrencePrecision;
use App\Enums\Severity;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Database\Factories\IncidentFactory;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * An incident (Phase 5A). Scoped by its own jurisdiction and classified;
 * views of confidential and secret incidents are access-logged. Its
 * related data (casualties, persons, groups, agencies, property) lives in
 * child tables, each scoped on its own state_id/lga_id (access rule 9).
 *
 * `details` holds the incident type's fields, validated on save against the
 * type's schema; `details_schema_version` records the schema version then.
 * `search_vector` is a generated English full-text column (GIN index).
 *
 * @property int $id
 * @property string $ulid
 * @property string|null $reference_no
 * @property int $incident_type_id
 * @property array<string, mixed> $details
 * @property int $details_schema_version
 * @property string $title
 * @property string $description
 * @property Severity $severity
 * @property CarbonImmutable $occurred_at
 * @property OccurrencePrecision $occurred_at_precision
 * @property int $state_id
 * @property int $lga_id
 * @property int|null $community_id
 * @property string|null $location_description
 * @property string|null $action_taken
 * @property IncidentStatus $status
 * @property Classification $classification
 * @property int $reporting_officer_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property float|null $latitude only with withCoordinates()
 * @property float|null $longitude only with withCoordinates()
 * @property-read IncidentType $type
 * @property-read State $state
 * @property-read Lga $lga
 * @property-read Community|null $community
 */
class Incident extends Model implements Classified, JurisdictionScoped
{
    use HasClassification;

    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    protected $fillable = ['title', 'description', 'severity', 'occurred_at', 'occurred_at_precision', 'location_description', 'action_taken'];

    protected $hidden = [
        'id', 'incident_type_id', 'state_id', 'lga_id', 'community_id', 'location', 'search_vector',
        'reporting_officer_id', 'created_by', 'updated_by',
    ];

    protected $attributes = [
        'classification' => 'restricted',
        'status' => 'draft',
        'details' => '{}',
        'occurred_at_precision' => 'exact',
    ];

    protected function casts(): array
    {
        return [
            'details' => JsonObject::class,
            'details_schema_version' => 'integer',
            'severity' => Severity::class,
            'occurred_at' => 'immutable_datetime',
            'occurred_at_precision' => OccurrencePrecision::class,
            'status' => IncidentStatus::class,
            'classification' => Classification::class,
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Binary geography and the search vector are not useful in the audit
     * log; location changes are a named event (SaveIncident).
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['location', 'search_vector'];
    }

    public static function pointFrom(?float $latitude, ?float $longitude): ?Expression
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        // Only floats, formatted with %F (locale-independent): nothing to inject.
        // @phpstan-ignore argument.type
        return DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', $longitude, $latitude));
    }

    /**
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('incidents.*');
        }

        return $query->selectRaw('ST_Y(incidents.location::geometry) AS latitude, ST_X(incidents.location::geometry) AS longitude');
    }

    /** Views of confidential and secret incidents are access-logged. */
    public function logsViews(): bool
    {
        return $this->classificationLevel() !== Classification::Restricted;
    }

    /**
     * @return BelongsTo<IncidentType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class, 'incident_type_id');
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /**
     * @return BelongsTo<Community, $this>
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reportingOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporting_officer_id');
    }

    /**
     * @return HasMany<IncidentCasualty, $this>
     */
    public function casualties(): HasMany
    {
        return $this->hasMany(IncidentCasualty::class);
    }

    /**
     * @return HasMany<IncidentPerson, $this>
     */
    public function persons(): HasMany
    {
        return $this->hasMany(IncidentPerson::class);
    }

    /**
     * @return HasMany<IncidentCriminalGroup, $this>
     */
    public function criminalGroups(): HasMany
    {
        return $this->hasMany(IncidentCriminalGroup::class);
    }

    /**
     * @return HasMany<IncidentAgency, $this>
     */
    public function agencies(): HasMany
    {
        return $this->hasMany(IncidentAgency::class);
    }

    /**
     * @return HasMany<IncidentProperty, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(IncidentProperty::class);
    }
}
