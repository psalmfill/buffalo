<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\FormationStatus;
use App\Enums\FormationType;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasRichText;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Database\Factories\AgencyFormationFactory;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A formation of a security agency (Phase 4A): a command, division,
 * station, base, checkpoint… Scoped by its own jurisdiction (state_id and
 * lga_id) and classified; military locations can be sensitive. Every view
 * is access-logged.
 *
 * `location` is a geography(Point, 4326): write it with pointFrom(), read it
 * with withCoordinates(). `search_name` is a generated column with a
 * trigram index. The database enforces the agency's state, the parent's
 * agency, the absence of cycles and the location-in-LGA rule.
 *
 * @property int $id
 * @property string $ulid
 * @property int $security_agency_id
 * @property int|null $parent_formation_id
 * @property string $name
 * @property FormationType $formation_type
 * @property int $state_id
 * @property int $lga_id
 * @property int|null $community_id
 * @property string|null $address
 * @property string|null $official_phone
 * @property string|null $commander_title
 * @property string|null $commander_name
 * @property string|null $commander_rank
 * @property int|null $personnel_strength
 * @property bool $is_temporary
 * @property CarbonImmutable|null $active_from
 * @property CarbonImmutable|null $active_to
 * @property FormationStatus $status
 * @property Classification $classification
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property float|null $latitude only with withCoordinates()
 * @property float|null $longitude only with withCoordinates()
 * @property-read SecurityAgency $agency
 * @property-read AgencyFormation|null $parent
 * @property-read State $state
 * @property-read Lga $lga
 * @property-read Community|null $community
 */
class AgencyFormation extends Model implements Classified, JurisdictionScoped
{
    use HasClassification;

    /** @use HasFactory<AgencyFormationFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasRichText;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    protected $fillable = [
        'name', 'formation_type', 'address', 'official_phone', 'commander_title', 'commander_name',
        'commander_rank', 'personnel_strength', 'is_temporary', 'active_from', 'active_to', 'status', 'notes',
    ];

    protected $hidden = [
        'id', 'security_agency_id', 'parent_formation_id', 'state_id', 'lga_id', 'community_id',
        'location', 'search_name', 'created_by', 'updated_by',
    ];

    protected $attributes = [
        'classification' => 'restricted',
        'status' => 'active',
        'is_temporary' => false,
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
            'formation_type' => FormationType::class,
            'status' => FormationStatus::class,
            'classification' => Classification::class,
            'personnel_strength' => 'integer',
            'is_temporary' => 'boolean',
            'active_from' => 'immutable_date',
            'active_to' => 'immutable_date',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Binary geography is not useful in the audit log; location changes are
     * logged as a named event with coordinates (SaveAgencyFormation).
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['location', 'search_name'];
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
     * @param  Builder<AgencyFormation>  $query
     * @return Builder<AgencyFormation>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('agency_formations.*');
        }

        return $query->selectRaw('ST_Y(agency_formations.location::geometry) AS latitude, ST_X(agency_formations.location::geometry) AS longitude');
    }

    /**
     * Fuzzy name search: a substring match or a trigram word match, both
     * served by the trigram index.
     *
     * @param  Builder<AgencyFormation>  $query
     * @return Builder<AgencyFormation>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query
            ->where(fn (Builder $q) => $q
                ->whereRaw("agency_formations.search_name LIKE '%' || buffalo_search_text(?) || '%'", [addcslashes($term, '%_\\')])
                ->orWhereRaw('buffalo_search_text(?) <% agency_formations.search_name', [$term]))
            ->orderByRaw('word_similarity(buffalo_search_text(?), agency_formations.search_name) DESC', [$term]);
    }

    /**
     * @return BelongsTo<SecurityAgency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(SecurityAgency::class, 'security_agency_id');
    }

    /**
     * @return BelongsTo<AgencyFormation, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(AgencyFormation::class, 'parent_formation_id');
    }

    /**
     * @return HasMany<AgencyFormation, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(AgencyFormation::class, 'parent_formation_id');
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
}
