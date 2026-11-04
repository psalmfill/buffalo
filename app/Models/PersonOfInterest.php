<?php

namespace App\Models;

use App\Enums\EyeColour;
use App\Enums\HairColour;
use App\Enums\MaritalStatus;
use App\Enums\PoiStatus;
use App\Enums\RiskLevel;
use App\Enums\TribalMarks;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasRichText;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Audit\AuditLog;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Database\Factories\PersonOfInterestFactory;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use LogicException;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

/**
 * A person of interest (Phase 3B): one per person.
 *
 * Visibility: jurisdiction (state_id/lga_id, always equal to the person's:
 * composite foreign key) AND the person must be visible: the "person"
 * global scope applies the person's jurisdiction and classification
 * scopes. There is no classification column of its own.
 *
 * Personal details (Phase 11G.4) are collected only for persons of
 * interest (data minimisation): criminal record, height, origin (which
 * never affects jurisdiction), hometown, marital status, hair and eye
 * colour, tribal marks. Religion and political affiliations are special
 * categories: encrypted with CipherSweet and NO blind index, so they can
 * never be searched, filtered or matched on; they never reach the activity
 * log (a "sensitive_details_changed" event names which changed) and appear
 * only on the person's page, never in lists, exports or bulk PDFs.
 *
 * Status and risk changes are named audit events with a comment
 * (ChangePoiStatus). Reads of the details are access-logged (context
 * "poi").
 *
 * @property int $id
 * @property string $ulid
 * @property int $person_id
 * @property int $state_id
 * @property int $lga_id
 * @property RiskLevel $risk_level
 * @property PoiStatus $status
 * @property list<string> $offence_categories threat type codes
 * @property string $known_offences
 * @property string|null $modus_operandi
 * @property string|null $criminal_record past records: arrests, charges, convictions, prison terms (rich text)
 * @property int|null $height_cm
 * @property int|null $origin_state_id
 * @property int|null $origin_lga_id
 * @property string|null $hometown
 * @property MaritalStatus|null $marital_status
 * @property string|null $religion a religions code (encrypted, no blind index)
 * @property string|null $political_affiliations encrypted, no blind index
 * @property HairColour|null $hair_colour
 * @property EyeColour|null $eye_colour
 * @property TribalMarks|null $has_tribal_marks
 * @property string|null $tribal_marks_description
 * @property string|null $last_known_location_description
 * @property CarbonImmutable|null $last_sighted_at
 * @property CarbonImmutable $review_due_at
 * @property CarbonImmutable|null $last_reviewed_at
 * @property int|null $last_reviewed_by
 * @property CarbonImmutable|null $review_reminded_for
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property float|null $latitude only with withCoordinates()
 * @property float|null $longitude only with withCoordinates()
 * @property-read Person $person
 * @property-read Lga $lga
 * @property-read State $state
 * @property-read User|null $reviewer
 * @property-read State|null $originState
 * @property-read Lga|null $originLga
 */
class PersonOfInterest extends Model implements CipherSweetEncrypted, JurisdictionScoped
{
    /** @use HasFactory<PersonOfInterestFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasRichText;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;
    use UsesCipherSweet {
        encryptRow as cipherSweetEncryptRow;
    }

    /** Special categories (Phase 11G.4): encrypted, never indexed. */
    public const ENCRYPTED = ['religion', 'political_affiliations'];

    /** None: the special categories must never be searchable. */
    public const BLIND_INDEXES = [];

    public const SENSITIVE_LABELS = ['religion' => 'religion', 'political_affiliations' => 'political affiliations'];

    /** The personal details of Phase 11G.4 (form, page, full grants). */
    public const DETAILS = [
        'criminal_record', 'height_cm', 'origin_state_id', 'origin_lga_id', 'hometown', 'marital_status', 'religion',
        'political_affiliations', 'hair_colour', 'eye_colour', 'has_tribal_marks', 'tribal_marks_description',
    ];

    protected $table = 'persons_of_interest';

    protected $fillable = ['offence_categories', 'known_offences', 'modus_operandi', 'last_known_location_description', 'last_sighted_at'];

    protected $hidden = ['id', 'person_id', 'state_id', 'lga_id', 'last_known_location', 'last_reviewed_by', 'created_by', 'updated_by', 'origin_state_id', 'origin_lga_id', 'religion', 'political_affiliations'];

    /**
     * Rich text (Phase 11B), sanitised on every save.
     *
     * @return list<string>
     */
    public function richTextFields(): array
    {
        return ['known_offences', 'modus_operandi', 'criminal_record'];
    }

    protected function casts(): array
    {
        return [
            'risk_level' => RiskLevel::class,
            'status' => PoiStatus::class,
            'offence_categories' => 'array',
            'last_sighted_at' => 'datetime',
            'review_due_at' => 'date:Y-m-d',
            'last_reviewed_at' => 'datetime',
            'review_reminded_for' => 'date:Y-m-d',
            'latitude' => 'float',
            'longitude' => 'float',
            'height_cm' => 'integer',
            'marital_status' => MaritalStatus::class,
            'hair_colour' => HairColour::class,
            'eye_colour' => EyeColour::class,
            'has_tribal_marks' => TribalMarks::class,
        ];
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        foreach (self::ENCRYPTED as $column) {
            $encryptedRow->addOptionalTextField($column);
        }
    }

    /**
     * Never save a partially loaded POI: the missing encrypted columns would
     * be written as NULL.
     */
    public function encryptRow(): void
    {
        if ($this->exists) {
            foreach (self::ENCRYPTED as $column) {
                if (! array_key_exists($column, $this->getAttributes())) {
                    throw new LogicException("A person of interest must be saved with its encrypted columns loaded ({$column} is missing).");
                }
            }
        }

        $this->cipherSweetEncryptRow();
    }

    protected static function booted(): void
    {
        // Visible only when the person is (jurisdiction AND classification).
        static::addGlobalScope('person', fn (Builder $query) => $query->whereHas('person'));
        static::created(fn (PersonOfInterest $poi) => $poi->logSensitiveChanges(created: true));
        static::updated(fn (PersonOfInterest $poi) => $poi->logSensitiveChanges(created: false));
    }

    /** Names which special categories changed, never their values. */
    private function logSensitiveChanges(bool $created): void
    {
        $dirty = $this->cipherSweetSavingUnencryptedAttributes;
        $fields = array_values(array_filter(self::ENCRYPTED, fn (string $column) => array_key_exists($column, $dirty)
            && ! ($created && ($dirty[$column] === null || $dirty[$column] === ''))));

        if ($fields === []) {
            return;
        }

        AuditLog::event(
            'sensitive_details_changed',
            ($created ? 'Sensitive details recorded: ' : 'Sensitive details changed: ').implode(', ', array_map(fn (string $f) => self::SENSITIVE_LABELS[$f], $fields)),
            $this,
            ['fields' => $fields],
        );
    }

    /**
     * Binary geography is not useful in the audit log; sightings are logged
     * as a named event.
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['last_known_location', ...self::ENCRYPTED];
    }

    public static function pointFrom(?float $latitude, ?float $longitude): ?Expression
    {
        return Community::pointFrom($latitude, $longitude);
    }

    /**
     * @param  Builder<PersonOfInterest>  $query
     * @return Builder<PersonOfInterest>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('persons_of_interest.*');
        }

        return $query->selectRaw('ST_Y(persons_of_interest.last_known_location::geometry) AS latitude, ST_X(persons_of_interest.last_known_location::geometry) AS longitude');
    }

    /**
     * @param  Builder<PersonOfInterest>  $query
     * @return Builder<PersonOfInterest>
     */
    public function scopeReviewOverdue(Builder $query): Builder
    {
        return $query->where('persons_of_interest.review_due_at', '<', CarbonImmutable::today()->toDateString());
    }

    /**
     * Highest risk first, then most recently sighted.
     *
     * @param  Builder<PersonOfInterest>  $query
     * @return Builder<PersonOfInterest>
     */
    public function scopeByPriority(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE persons_of_interest.risk_level WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END DESC")
            ->orderBy(DB::raw('persons_of_interest.last_sighted_at'), 'desc')
            ->orderByDesc('persons_of_interest.id');
    }

    public function isReviewOverdue(): bool
    {
        return $this->review_due_at->lessThan(CarbonImmutable::today());
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
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
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reviewed_by');
    }

    /**
     * State of origin (Phase 11G.4); never affects jurisdiction.
     *
     * @return BelongsTo<State, $this>
     */
    public function originState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'origin_state_id');
    }

    /**
     * LGA of origin, within the state of origin.
     *
     * @return BelongsTo<Lga, $this>
     */
    public function originLga(): BelongsTo
    {
        return $this->belongsTo(Lga::class, 'origin_lga_id');
    }
}
