<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\CoveragePeriodType;
use App\Enums\CoverageRating;
use App\Enums\CoverageReportStatus;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Database\Factories\CoverageReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A security coverage report (Phase 4A): one LGA's statement for a period
 * of which agencies are deployed where, what they cover, and the gaps.
 * Scoped by its own jurisdiction and classified; every view is logged.
 *
 * Workflow: draft → submitted → (returned → submitted)* → reviewed. A
 * reviewed report is locked (policies, and database triggers).
 *
 * @property int $id
 * @property string $ulid
 * @property int $state_id
 * @property int $lga_id
 * @property CoveragePeriodType $period_type
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CoverageRating|null $overall_rating
 * @property string|null $summary
 * @property string|null $gaps_identified
 * @property string|null $recommendations
 * @property CoverageReportStatus $status
 * @property int|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_comment
 * @property int|null $returned_by
 * @property CarbonImmutable|null $returned_at
 * @property string|null $return_reason
 * @property Classification $classification
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read State $state
 * @property-read Lga $lga
 * @property-read User|null $creator
 * @property-read User|null $submitter
 * @property-read User|null $reviewer
 * @property-read User|null $returner
 */
class CoverageReport extends Model implements Classified, JurisdictionScoped
{
    use HasClassification;

    /** @use HasFactory<CoverageReportFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    protected $fillable = ['overall_rating', 'summary', 'gaps_identified', 'recommendations'];

    protected $hidden = ['id', 'state_id', 'lga_id', 'submitted_by', 'reviewed_by', 'returned_by', 'created_by', 'updated_by'];

    protected $attributes = [
        'classification' => 'restricted',
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'period_type' => CoveragePeriodType::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'overall_rating' => CoverageRating::class,
            'status' => CoverageReportStatus::class,
            'classification' => Classification::class,
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
        ];
    }

    public function periodLabel(): string
    {
        return $this->period_type->describe($this->period_start, $this->period_end);
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
     * @return HasMany<CoverageReportDeployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(CoverageReportDeployment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
