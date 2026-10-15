<?php

namespace App\Models;

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Models\Concerns\HasRichText;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An organization in the NATIONAL DIRECTORY (Phase 4B): non-sensitive
 * identity data only (name, type, sector, registration). It has no location
 * and is not jurisdiction-scoped or classified: every intelligence role sees
 * every entry. Everything security-relevant lives on its sites
 * (OrganizationSite), which are scoped and classified.
 *
 * `search_name` is a generated column (name and other names, normalised)
 * with a trigram index.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property list<string> $other_names
 * @property OrganizationType $type
 * @property int $organization_sector_id
 * @property string|null $rc_number
 * @property string|null $website
 * @property string|null $description
 * @property OrganizationStatus $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read OrganizationSector $sector
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasRichText;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    protected $fillable = ['name', 'other_names', 'type', 'rc_number', 'website', 'description', 'status'];

    protected $hidden = ['id', 'organization_sector_id', 'search_name', 'created_by', 'updated_by'];

    protected $attributes = [
        'status' => 'active',
        'other_names' => '[]',
    ];

    /**
     * Rich text (Phase 11B), sanitised on every save.
     *
     * @return list<string>
     */
    public function richTextFields(): array
    {
        return ['description'];
    }

    protected function casts(): array
    {
        return [
            'other_names' => 'array',
            'type' => OrganizationType::class,
            'status' => OrganizationStatus::class,
        ];
    }

    /** @return list<string> */
    protected function auditExcept(): array
    {
        return ['search_name'];
    }

    /**
     * Fuzzy name search over the name and other names, through the trigram
     * index: the `%` (similarity) and `<%` (word similarity, for part of a
     * name) operators, with thresholds set for the transaction by the
     * caller (PersonSearch::withThresholds).
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query
            ->where(fn (Builder $q) => $q
                ->whereRaw('organizations.search_name % buffalo_search_text(?)', [$term])
                ->orWhereRaw('buffalo_search_text(?) <% organizations.search_name', [$term]))
            ->orderByRaw('word_similarity(buffalo_search_text(?), organizations.search_name) DESC', [$term]);
    }

    /**
     * @return BelongsTo<OrganizationSector, $this>
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(OrganizationSector::class, 'organization_sector_id');
    }

    /**
     * Sites, through their own scopes: only those the viewer can see.
     *
     * @return HasMany<OrganizationSite, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(OrganizationSite::class);
    }
}
