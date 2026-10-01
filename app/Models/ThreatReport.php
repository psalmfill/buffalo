<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\NotificationSeverity;
use App\Enums\ThreatCredibility;
use App\Enums\ThreatSourceType;
use App\Enums\ThreatStatus;
use App\Enums\ThreatUrgency;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\IsDisseminable;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\Disseminable;
use Carbon\CarbonImmutable;
use Database\Factories\ThreatReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A threat report (Phase 6B): a warning of what may be about to happen,
 * with an explicit credibility and urgency, an expected window and an
 * expiry. Scoped by its own jurisdiction and classified; views above
 * `restricted` are access-logged.
 *
 * Credibility, urgency, status and expiry change through named events
 * (ChangeThreatAssessment, ChangeThreatStatus, ExtendThreatReport,
 * ReactivateThreatReport, ExpireThreatReports), never silently.
 *
 * @property int $id
 * @property string $ulid
 * @property string $reference_no
 * @property int $threat_type_id
 * @property string $title
 * @property string $description
 * @property ThreatCredibility $credibility
 * @property ThreatUrgency $urgency
 * @property ThreatSourceType $source_type
 * @property string|null $source_notes
 * @property int $state_id
 * @property int $lga_id
 * @property int|null $community_id
 * @property int|null $radius_meters
 * @property string|null $target_description
 * @property CarbonImmutable|null $expected_from
 * @property CarbonImmutable|null $expected_until
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $expiry_warned_for
 * @property ThreatStatus $status
 * @property string|null $status_note
 * @property string|null $recommended_action
 * @property Classification $classification
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property float|null $latitude only with withCoordinates()
 * @property float|null $longitude only with withCoordinates()
 * @property-read ThreatType $threatType
 * @property-read Lga $lga
 * @property-read State $state
 * @property-read Community|null $community
 * @property-read User|null $creator
 */
class ThreatReport extends Model implements Classified, Disseminable
{
    use HasClassification;

    /** @use HasFactory<ThreatReportFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use IsDisseminable;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    protected $guarded = ['*'];

    protected $hidden = [
        'id', 'threat_type_id', 'state_id', 'lga_id', 'community_id', 'location',
        'expiry_warned_for', 'created_by', 'updated_by',
    ];

    protected $attributes = [
        'classification' => 'restricted',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'credibility' => ThreatCredibility::class,
            'urgency' => ThreatUrgency::class,
            'source_type' => ThreatSourceType::class,
            'status' => ThreatStatus::class,
            'classification' => Classification::class,
            'radius_meters' => 'integer',
            'expected_from' => 'immutable_datetime',
            'expected_until' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'expiry_warned_for' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Credibility, urgency, status and expiry changes are named events with
     * their comment; the location is logged by coordinates; the warning
     * bookkeeping is not an audited change.
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['location', 'expiry_warned_for', 'credibility', 'urgency', 'status', 'status_note', 'expires_at'];
    }

    public function logsViews(): bool
    {
        return $this->classificationLevel() !== Classification::Restricted;
    }

    public function isActive(): bool
    {
        return $this->status === ThreatStatus::Active;
    }

    public function disseminationType(): string
    {
        return 'threat_report';
    }

    public function disseminationLabel(): string
    {
        return $this->reference_no;
    }

    public function disseminationKind(): string
    {
        return 'Threat report';
    }

    public function disseminationPath(): string
    {
        return '/threats/'.$this->ulid;
    }

    public function disseminationSeverity(): NotificationSeverity
    {
        return match ($this->urgency) {
            ThreatUrgency::Immediate => NotificationSeverity::Critical,
            ThreatUrgency::Priority => NotificationSeverity::Warning,
            ThreatUrgency::Routine => NotificationSeverity::Info,
        };
    }

    public function warrantsDissemination(): bool
    {
        return $this->isActive() && $this->urgency !== ThreatUrgency::Routine;
    }

    public function acknowledgementHours(): int
    {
        return $this->urgency === ThreatUrgency::Immediate
            ? (int) config('buffalo.disseminations.remind_immediate_hours')
            : (int) config('buffalo.disseminations.remind_other_hours');
    }

    /**
     * @param  Builder<ThreatReport>  $query
     * @return Builder<ThreatReport>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('threat_reports.*');
        }

        return $query->selectRaw('ST_Y(threat_reports.location::geometry) AS latitude, ST_X(threat_reports.location::geometry) AS longitude');
    }

    /**
     * @param  Builder<ThreatReport>  $query
     * @return Builder<ThreatReport>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('threat_reports.status', ThreatStatus::Active->value);
    }

    /**
     * Most urgent first, then the earliest expected window, then expiry.
     *
     * @param  Builder<ThreatReport>  $query
     * @return Builder<ThreatReport>
     */
    public function scopeByUrgency(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE threat_reports.urgency WHEN 'immediate' THEN 3 WHEN 'priority' THEN 2 ELSE 1 END DESC")
            ->orderByRaw('threat_reports.expected_from ASC NULLS LAST')
            ->orderBy('threat_reports.expires_at')
            ->orderByDesc('threat_reports.id');
    }

    /**
     * @return BelongsTo<ThreatType, $this>
     */
    public function threatType(): BelongsTo
    {
        return $this->belongsTo(ThreatType::class);
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
