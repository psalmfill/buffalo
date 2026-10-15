<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\ConcernLevel;
use App\Enums\FlashpointKind;
use App\Enums\FlashpointStatus;
use App\Enums\NotificationSeverity;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasRichText;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\IsDisseminable;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\Disseminable;
use Carbon\CarbonImmutable;
use Database\Factories\FlashpointFactory;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A flashpoint or blackspot (Phase 6A): an area (point + radius) of
 * recurring tension or recurring incidents. Scoped by its own jurisdiction
 * and classified; views above `restricted` are access-logged.
 *
 * The current concern level, status, recommended action, last assessment
 * and review date change only through an assessment (AssessFlashpoint);
 * creating a flashpoint records the first one. `location` is a
 * geography(Point, 4326) inside the LGA boundary (trigger); areas are
 * matched with ST_DWithin on the GIST index.
 *
 * @property int $id
 * @property string $ulid
 * @property FlashpointKind $kind
 * @property string $name
 * @property int $threat_type_id
 * @property ConcernLevel $concern_level
 * @property int $state_id
 * @property int $lga_id
 * @property int|null $community_id
 * @property int $radius_meters
 * @property string $description
 * @property string|null $recommended_action
 * @property FlashpointStatus $status
 * @property Classification $classification
 * @property CarbonImmutable $last_assessed_at
 * @property int|null $last_assessed_by
 * @property CarbonImmutable $review_due_at
 * @property CarbonImmutable|null $review_reminded_for
 * @property CarbonImmutable|null $escalation_notified_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property float|null $latitude only with withCoordinates()
 * @property float|null $longitude only with withCoordinates()
 * @property-read ThreatType $threatType
 * @property-read Lga $lga
 * @property-read State $state
 * @property-read Community|null $community
 * @property-read User|null $assessor
 */
class Flashpoint extends Model implements Classified, Disseminable
{
    use HasClassification;

    /** @use HasFactory<FlashpointFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasRichText;
    use HasUlidRouteKey;
    use IsDisseminable;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    /**
     * The largest radius (metres; also a CHECK constraint). Area matching
     * first filters on this constant distance, which the GIST index on
     * `location` can serve, then checks each row's own radius: a per-row
     * distance alone cannot use the index.
     */
    public const MAX_RADIUS = 50000;

    /**
     * SQL: does the area of a flashpoints row contain $point (an SQL
     * expression)? Both arguments are fixed SQL written in code.
     *
     * @param  literal-string  $flashpoint  the table or alias
     * @param  literal-string  $point
     * @return literal-string
     */
    public static function containsSql(string $flashpoint, string $point): string
    {
        return 'ST_DWithin('.$flashpoint.'.location, '.$point.', '.self::MAX_RADIUS.')'
            .' AND ST_DWithin('.$flashpoint.'.location, '.$point.', '.$flashpoint.'.radius_meters)';
    }

    protected $guarded = ['*'];

    protected $hidden = [
        'id', 'threat_type_id', 'state_id', 'lga_id', 'community_id', 'location', 'search_name', 'search_vector',
        'last_assessed_by', 'created_by', 'updated_by', 'review_reminded_for', 'escalation_notified_at',
    ];

    protected $attributes = [
        'classification' => 'restricted',
        'status' => 'active',
        'radius_meters' => 1000,
    ];

    /**
     * Rich text (Phase 11B), sanitised on every save.
     *
     * @return list<string>
     */
    public function richTextFields(): array
    {
        return ['description', 'recommended_action'];
    }

    protected function casts(): array
    {
        return [
            'kind' => FlashpointKind::class,
            'concern_level' => ConcernLevel::class,
            'status' => FlashpointStatus::class,
            'classification' => Classification::class,
            'radius_meters' => 'integer',
            'last_assessed_at' => 'immutable_datetime',
            'review_due_at' => 'immutable_date',
            'review_reminded_for' => 'immutable_date',
            'escalation_notified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Location changes are logged as a named event with coordinates;
     * reminder bookkeeping is not an audited change.
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['location', 'search_name', 'search_vector', 'review_reminded_for', 'escalation_notified_at'];
    }

    public function logsViews(): bool
    {
        return $this->classificationLevel() !== Classification::Restricted;
    }

    public static function pointFrom(float $latitude, float $longitude): Expression
    {
        // Only floats, formatted with %F (locale-independent): nothing to inject.
        // @phpstan-ignore argument.type
        return DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', $longitude, $latitude));
    }

    public function disseminationType(): string
    {
        return 'flashpoint';
    }

    public function disseminationLabel(): string
    {
        return $this->name;
    }

    public function disseminationKind(): string
    {
        return $this->kind->label();
    }

    public function disseminationPath(): string
    {
        return '/flashpoints/'.$this->ulid;
    }

    public function disseminationSeverity(): NotificationSeverity
    {
        return match ($this->concern_level) {
            ConcernLevel::Severe => NotificationSeverity::Critical,
            ConcernLevel::High => NotificationSeverity::Warning,
            default => NotificationSeverity::Info,
        };
    }

    public function warrantsDissemination(): bool
    {
        return in_array($this->status, [FlashpointStatus::Active, FlashpointStatus::Monitoring], true)
            && in_array($this->concern_level, [ConcernLevel::High, ConcernLevel::Severe], true);
    }

    public function acknowledgementHours(): int
    {
        return (int) config('buffalo.disseminations.remind_other_hours');
    }

    public function isReviewOverdue(): bool
    {
        return $this->review_due_at->lessThan(CarbonImmutable::today());
    }

    /**
     * @param  Builder<Flashpoint>  $query
     * @return Builder<Flashpoint>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('flashpoints.*');
        }

        return $query->selectRaw('ST_Y(flashpoints.location::geometry) AS latitude, ST_X(flashpoints.location::geometry) AS longitude');
    }

    /**
     * Active and monitoring areas: the ones matched against new incidents.
     *
     * @param  Builder<Flashpoint>  $query
     * @return Builder<Flashpoint>
     */
    public function scopeWatched(Builder $query): Builder
    {
        return $query->whereIn('flashpoints.status', [FlashpointStatus::Active->value, FlashpointStatus::Monitoring->value]);
    }

    /**
     * Fuzzy name search (trigram index), as for formations.
     *
     * @param  Builder<Flashpoint>  $query
     * @return Builder<Flashpoint>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query
            ->where(fn (Builder $q) => $q
                ->whereRaw("flashpoints.search_name LIKE '%' || buffalo_search_text(?) || '%'", [addcslashes($term, '%_\\')])
                ->orWhereRaw('buffalo_search_text(?) <% flashpoints.search_name', [$term]))
            ->orderByRaw('word_similarity(buffalo_search_text(?), flashpoints.search_name) DESC', [$term]);
    }

    /**
     * Most serious first, then the earliest review.
     *
     * @param  Builder<Flashpoint>  $query
     * @return Builder<Flashpoint>
     */
    public function scopeByConcern(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE flashpoints.concern_level WHEN 'severe' THEN 4 WHEN 'high' THEN 3 WHEN 'moderate' THEN 2 ELSE 1 END DESC")
            ->orderBy('flashpoints.review_due_at')
            ->orderByDesc('flashpoints.id');
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
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_assessed_by');
    }

    /**
     * @return HasMany<FlashpointAssessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(FlashpointAssessment::class);
    }
}
