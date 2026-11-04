<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\CoveragePeriodType;
use App\Enums\CoverageRating;
use App\Enums\CoverageReportStatus;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasRichText;
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
 * A security report (Phase 4A as "coverage report"; renamed in the interface
 * in Phase 11G): one LGA's written report, with optional photos and videos
 * and, optionally, which agencies are deployed where, what they cover, and
 * the gaps. `summary` holds the written report (labelled "Report"). Scoped
 * by its own jurisdiction and classified; every view is logged.
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
 * @property string|null $title optional; displayTitle() otherwise
 * @property CarbonImmutable $report_date
 * @property string|null $summary the written report ("Report")
 * @property string|null $summary_text its plain-text copy (Phase 11B)
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
    use HasRichText;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    protected $fillable = ['title', 'report_date', 'overall_rating', 'summary', 'gaps_identified', 'recommendations'];

    protected $hidden = ['id', 'state_id', 'lga_id', 'submitted_by', 'reviewed_by', 'returned_by', 'created_by', 'updated_by'];

    protected $attributes = [
        'classification' => 'restricted',
        'status' => 'draft',
    ];

    /**
     * Rich text (Phase 11B), sanitised on every save.
     *
     * @return list<string>
     */
    public function richTextFields(): array
    {
        return ['summary', 'gaps_identified', 'recommendations'];
    }

    protected function casts(): array
    {
        return [
            'report_date' => 'immutable_date',
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

    /** The title, or "Security report — [LGA] — [date]" when none was given. */
    public function displayTitle(): string
    {
        if ($this->title !== null && trim($this->title) !== '') {
            return $this->title;
        }

        $date = ($this->report_date ?? $this->created_at ?? CarbonImmutable::today())->format('j M Y');

        return 'Security report — '.($this->lga->name ?? 'LGA').' — '.$date;
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
     * @return HasMany<CoverageReportAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(CoverageReportAttachment::class);
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
