<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\CriminalGroupStatus;
use App\Enums\CriminalGroupType;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Database\Factories\CriminalGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A criminal group (Phase 3B): owned and scoped by its home jurisdiction
 * (state_id/lga_id), and classified. Areas of operation elsewhere never
 * grant visibility. Every view of a group is access-logged.
 *
 * search_name is a generated column (name and other names, normalised)
 * with a trigram index.
 *
 * @property int $id
 * @property string $ulid
 * @property int $state_id
 * @property int $lga_id
 * @property string $name
 * @property list<string> $other_names
 * @property CriminalGroupType $type
 * @property string $description
 * @property int|null $estimated_size
 * @property CriminalGroupStatus $status
 * @property Classification $classification
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property-read Lga $lga
 * @property-read State $state
 */
class CriminalGroup extends Model implements Classified, JurisdictionScoped
{
    use HasClassification;

    /** @use HasFactory<CriminalGroupFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    /** Word similarity for the name search (`<%`, set per transaction with Trigram). */
    public const SEARCH_SIMILARITY = 0.4;

    protected $fillable = ['name', 'other_names', 'type', 'description', 'estimated_size', 'status'];

    protected $hidden = ['id', 'state_id', 'lga_id', 'search_name', 'created_by', 'updated_by'];

    protected $attributes = [
        'classification' => 'restricted',
        'other_names' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'other_names' => 'array',
            'type' => CriminalGroupType::class,
            'status' => CriminalGroupStatus::class,
            'classification' => Classification::class,
            'estimated_size' => 'integer',
        ];
    }

    /** @return list<string> */
    protected function auditExcept(): array
    {
        return ['search_name'];
    }

    /**
     * Fuzzy name search (name and other names).
     *
     * @param  Builder<CriminalGroup>  $query
     * @return Builder<CriminalGroup>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query
            ->where(fn (Builder $q) => $q
                ->whereRaw("criminal_groups.search_name LIKE '%' || buffalo_search_text(?) || '%'", [addcslashes($term, '%_\\')])
                // The trigram index operator: run inside Trigram::withWordSimilarity(self::SEARCH_SIMILARITY, …).
                ->orWhereRaw('buffalo_search_text(?) <% criminal_groups.search_name', [$term]))
            ->orderByRaw('word_similarity(buffalo_search_text(?), criminal_groups.search_name) DESC', [$term]);
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
     * @return HasMany<CriminalGroupArea, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(CriminalGroupArea::class);
    }

    /**
     * @return HasMany<CriminalGroupMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CriminalGroupMember::class);
    }
}
