<?php

namespace App\Models;

use App\Enums\VulnerableCategory;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Database\Factories\VulnerablePopulationStatFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Vulnerable population figures (Phase 2C): AGGREGATE COUNTS ONLY, never
 * names or identifying details. There is deliberately no free-text field.
 *
 * Each row is a figure for an LGA (community_id null: LGA-wide) or one
 * community, per category, as of a date. New figures are new rows; the
 * latest date per series is the current figure. Jurisdiction-scoped;
 * always restricted (no classification column).
 *
 * @property int $id
 * @property string $ulid
 * @property int $state_id
 * @property int $lga_id
 * @property int|null $community_id
 * @property VulnerableCategory $category
 * @property int $adult_male
 * @property int $adult_female
 * @property int $child_male
 * @property int $child_female
 * @property Carbon $as_of_date
 * @property string $source
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property-read Lga $lga
 * @property-read Community|null $community
 */
class VulnerablePopulationStat extends Model implements JurisdictionScoped
{
    /** @use HasFactory<VulnerablePopulationStatFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    public const COUNTS = ['adult_male', 'adult_female', 'child_male', 'child_female'];

    protected $fillable = ['category', 'adult_male', 'adult_female', 'child_male', 'child_female', 'as_of_date', 'source'];

    protected $hidden = ['id', 'state_id', 'lga_id', 'community_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'category' => VulnerableCategory::class,
            'adult_male' => 'integer',
            'adult_female' => 'integer',
            'child_male' => 'integer',
            'child_female' => 'integer',
            'as_of_date' => 'date',
        ];
    }

    public function total(): int
    {
        return $this->adult_male + $this->adult_female + $this->child_male + $this->child_female;
    }

    /**
     * The latest figure of each series (LGA or community, category): one
     * row per series, the one with the latest date.
     *
     * @param  Builder<VulnerablePopulationStat>  $query
     * @return Builder<VulnerablePopulationStat>
     */
    public function scopeLatestPerSeries(Builder $query): Builder
    {
        // PostgreSQL DISTINCT ON: the first row of each series in this order.
        return $query
            ->select('vulnerable_population_stats.*')
            ->distinct('vulnerable_population_stats.lga_id', 'vulnerable_population_stats.community_id', 'vulnerable_population_stats.category')
            ->orderBy('vulnerable_population_stats.lga_id')
            ->orderBy('vulnerable_population_stats.community_id')
            ->orderBy('vulnerable_population_stats.category')
            ->orderByDesc('vulnerable_population_stats.as_of_date')
            ->orderByDesc('vulnerable_population_stats.id');
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
