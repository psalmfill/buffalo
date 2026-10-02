<?php

namespace App\Models;

use App\Enums\CommunityType;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Database\Factories\CommunityFactory;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A community in an LGA (Phase 2B). Jurisdiction-scoped by state_id/lga_id.
 *
 * `location` is a geography(Point, 4326): write it with pointFrom(), read it
 * with the withCoordinates() scope (latitude/longitude). `search_names` is a
 * generated column (name and other names, lowercased, unaccented).
 *
 * @property int $id
 * @property string $ulid
 * @property int $state_id
 * @property int $lga_id
 * @property int|null $ward_id
 * @property int|null $traditional_ruler_id a person holding the traditional_ruler role (Phase 3A)
 * @property string $name
 * @property list<string> $other_names
 * @property CommunityType $type
 * @property int|null $population_estimate
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property float|null $latitude only with withCoordinates()
 * @property float|null $longitude only with withCoordinates()
 * @property-read Lga $lga
 * @property-read State $state
 * @property-read Ward|null $ward
 * @property-read Person|null $traditionalRuler null when not visible to the current user
 */
class Community extends Model implements JurisdictionScoped
{
    /** @use HasFactory<CommunityFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    /** Fuzzy search: minimum pg_trgm word similarity for a match. */
    public const SEARCH_SIMILARITY = 0.4;

    protected $fillable = ['name', 'other_names', 'type', 'population_estimate', 'notes'];

    protected $hidden = ['id', 'state_id', 'lga_id', 'ward_id', 'traditional_ruler_id', 'location', 'search_names', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'other_names' => 'array',
            'type' => CommunityType::class,
            'population_estimate' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Binary geography is not useful in the audit log; location changes are
     * logged as a named event with coordinates (SaveCommunity).
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['location', 'search_names'];
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
     * @param  Builder<Community>  $query
     * @return Builder<Community>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('communities.*');
        }

        return $query->selectRaw('ST_Y(communities.location::geometry) AS latitude, ST_X(communities.location::geometry) AS longitude');
    }

    /**
     * Fuzzy name search over the name and other names (pg_trgm word
     * similarity, accent- and case-insensitive), best matches first.
     *
     * @param  Builder<Community>  $query
     * @return Builder<Community>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query
            ->where(fn (Builder $q) => $q
                ->whereRaw("communities.search_names LIKE '%' || lower(buffalo_unaccent(?)) || '%'", [addcslashes($term, '%_\\')])
                // The trigram index operator: run inside Trigram::withWordSimilarity(self::SEARCH_SIMILARITY, …).
                ->orWhereRaw('lower(buffalo_unaccent(?)) <% communities.search_names', [$term]))
            ->orderByRaw('word_similarity(lower(buffalo_unaccent(?)), communities.search_names) DESC', [$term]);
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
     * @return BelongsTo<Ward, $this>
     */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    /**
     * Loaded through the person scopes: null when the ruler is not visible
     * to the current user.
     *
     * @return BelongsTo<Person, $this>
     */
    public function traditionalRuler(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'traditional_ruler_id');
    }
}
