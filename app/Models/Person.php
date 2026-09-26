<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\Gender;
use App\Enums\PersonRoleType;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Audit\AuditLog;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use App\Support\Persons\PhoneNumber;
use Carbon\CarbonImmutable;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\Image\Enums\Fit;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * A person record (Phase 3A): the most sensitive data in BUFFALO.
 *
 * - Jurisdiction-scoped by the owning state_id/lga_id, and classified: both
 *   scopes apply. Every view is access-logged (LogsReads).
 * - date_of_birth, nin, phone and address are encrypted with CipherSweet
 *   (ciphertext in the database, plaintext in memory once retrieved). DOB,
 *   NIN and phone have blind indexes (dob_bidx, nin_bidx, phone_bidx) for
 *   exact-match search. They are hidden from serialisation and never
 *   written to the activity log: a named "protected_fields_changed" event
 *   records WHICH of them changed, never their values.
 * - search_name is a generated column (see the migration).
 * - Photos: media collection "photos" on the private evidence disk, newest
 *   first is the current photo. Never served by URL (PersonPhotoController).
 *
 * @property int $id
 * @property string $ulid
 * @property int $state_id
 * @property int $lga_id
 * @property int|null $community_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $other_names
 * @property string $search_name
 * @property Gender $gender
 * @property string|null $date_of_birth Y-m-d (encrypted at rest)
 * @property int|null $approximate_age
 * @property string|null $nin 11 digits (encrypted at rest)
 * @property string|null $phone +234XXXXXXXXXX (encrypted at rest)
 * @property string|null $address encrypted at rest
 * @property string|null $occupation
 * @property string $nationality
 * @property string|null $description
 * @property Classification $classification
 * @property bool $is_deceased
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read string $name
 * @property-read State $state
 * @property-read Lga $lga
 * @property-read Community|null $community
 * @property-read Collection<int, PersonRole> $roles
 * @property-read Collection<int, PersonAlias> $aliases
 */
class Person extends Model implements CipherSweetEncrypted, Classified, HasMedia, JurisdictionScoped
{
    use HasClassification;

    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use InteractsWithMedia;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;
    use UsesCipherSweet {
        encryptRow as cipherSweetEncryptRow;
    }

    /** Encrypted columns. */
    public const ENCRYPTED = ['date_of_birth', 'nin', 'phone', 'address'];

    /** Blind index name per encrypted column (exact-match search). */
    public const BLIND_INDEXES = ['date_of_birth' => 'dob_bidx', 'nin' => 'nin_bidx', 'phone' => 'phone_bidx'];

    /** Labels for the audit log and the UI. */
    public const PROTECTED_LABELS = [
        'date_of_birth' => 'date of birth',
        'nin' => 'NIN',
        'phone' => 'phone number',
        'address' => 'address',
    ];

    public const PHOTOS = 'photos';

    protected $table = 'persons';

    protected $fillable = [
        'first_name', 'last_name', 'other_names', 'gender', 'date_of_birth', 'approximate_age',
        'nin', 'phone', 'address', 'occupation', 'nationality', 'description', 'is_deceased',
    ];

    protected $hidden = [
        'id', 'state_id', 'lga_id', 'community_id', 'created_by', 'updated_by', 'search_name',
        'date_of_birth', 'nin', 'phone', 'address',
    ];

    protected $attributes = [
        'classification' => 'restricted',
        'nationality' => 'Nigerian',
        'is_deceased' => false,
        'gender' => 'unknown',
    ];

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'classification' => Classification::class,
            'approximate_age' => 'integer',
            'is_deceased' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (Person $person) => $person->logProtectedFieldChanges(created: true));
        static::updated(fn (Person $person) => $person->logProtectedFieldChanges(created: false));
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        foreach (self::ENCRYPTED as $column) {
            $encryptedRow->addOptionalTextField($column);
        }

        // Keyed hashes (the key never leaves the application), so the fast
        // hash is sufficient; full 256-bit output: exact matches only.
        foreach (self::BLIND_INDEXES as $column => $name) {
            $encryptedRow->addBlindIndex($column, new BlindIndex($name, [], 256, true));
        }
    }

    /**
     * Never save a partially loaded person: the missing encrypted columns
     * would be written as NULL.
     */
    public function encryptRow(): void
    {
        if ($this->exists) {
            foreach (self::ENCRYPTED as $column) {
                if (! array_key_exists($column, $this->getAttributes())) {
                    throw new LogicException("A person must be saved with its encrypted columns loaded ({$column} is missing).");
                }
            }
        }

        $this->cipherSweetEncryptRow();
    }

    /**
     * Blind indexes only for values that are present; an index whose value
     * was cleared is removed (the package would index NULL).
     */
    public function updateBlindIndexes(): void
    {
        $row = static::getCipherSweetEncryptedRow();
        $table = DB::connection($this->getConnectionName())->table('blind_indexes');

        foreach (self::BLIND_INDEXES as $column => $name) {
            $value = $this->getAttributes()[$column] ?? null;
            $key = ['indexable_type' => $this->getMorphClass(), 'indexable_id' => $this->getKey(), 'name' => $name];

            if ($value === null || $value === '') {
                (clone $table)->where($key)->delete();

                continue;
            }

            (clone $table)->upsert([...$key, 'value' => $row->getBlindIndex($name, [$column => $value])], ['indexable_type', 'indexable_id', 'name'], ['value']);
        }
    }

    /**
     * The blind index a search value would have (exact match), for
     * whereBlind()-style queries on other code paths.
     */
    public static function blindIndexFor(string $column, string $value): string
    {
        $index = static::getCipherSweetEncryptedRow()->getBlindIndex(self::BLIND_INDEXES[$column], [$column => $value]);

        return is_string($index) ? $index : (string) $index['value'];
    }

    /**
     * Phone numbers are stored (and indexed) only as +234XXXXXXXXXX.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function phone(): Attribute
    {
        return Attribute::make(set: function (?string $value): ?string {
            if ($value === null || trim($value) === '') {
                return null;
            }

            return PhoneNumber::normalise($value) ?? throw new InvalidArgumentException('Invalid phone number.');
        });
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function nin(): Attribute
    {
        return Attribute::make(set: function (?string $value): ?string {
            if ($value === null || trim($value) === '') {
                return null;
            }

            $digits = preg_replace('/\s+/', '', $value) ?? '';

            return preg_match('/^\d{11}$/', $digits) === 1 ? $digits : throw new InvalidArgumentException('A NIN is exactly 11 digits.');
        });
    }

    /**
     * Full name: first, other names, last.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::make(get: fn (): string => trim(preg_replace('/\s+/', ' ', "{$this->first_name} {$this->other_names} {$this->last_name}") ?? ''));
    }

    /**
     * Encrypted values and blind indexes never reach the activity log.
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return [...self::ENCRYPTED, 'search_name'];
    }

    /**
     * Records which protected (encrypted) fields a save set or changed,
     * never their values.
     */
    private function logProtectedFieldChanges(bool $created): void
    {
        $dirty = $this->cipherSweetSavingUnencryptedAttributes;
        $fields = [];

        foreach (self::ENCRYPTED as $column) {
            if (! array_key_exists($column, $dirty) || ($created && ($dirty[$column] === null || $dirty[$column] === ''))) {
                continue;
            }

            $fields[] = $column;
        }

        if ($fields === []) {
            return;
        }

        $labels = implode(', ', array_map(fn (string $field): string => self::PROTECTED_LABELS[$field], $fields));

        AuditLog::event(
            'protected_fields_changed',
            ($created ? 'Protected fields recorded: ' : 'Protected fields changed: ').$labels,
            $this,
            ['fields' => $fields],
        );
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PHOTOS)
            ->useDisk('evidence')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?BaseMedia $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections(self::PHOTOS)
            ->queued()
            ->fit(Fit::Crop, 200, 200);

        $this->addMediaConversion('preview')
            ->performOnCollections(self::PHOTOS)
            ->queued()
            ->fit(Fit::Max, 800, 800);
    }

    /** The current photo: the newest in the photos collection. */
    public function currentPhoto(): ?Media
    {
        /** @var Media|null */
        return $this->media()->where('collection_name', self::PHOTOS)->latest('id')->first();
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

    /**
     * @return HasMany<PersonRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(PersonRole::class);
    }

    public function holdsRole(PersonRoleType $role): bool
    {
        return $this->roles()->where('role', $role->value)->where('is_active', true)->exists();
    }

    /**
     * @return HasMany<PersonAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(PersonAlias::class);
    }

    /**
     * Associations recorded from this person's side (see PersonAssociation
     * for both sides).
     *
     * @return HasMany<PersonAssociation, $this>
     */
    public function associations(): HasMany
    {
        return $this->hasMany(PersonAssociation::class);
    }

    /**
     * @return HasMany<PersonAssociation, $this>
     */
    public function associatedBy(): HasMany
    {
        return $this->hasMany(PersonAssociation::class, 'associated_person_id');
    }

    /**
     * @return HasMany<PoliticalOffice, $this>
     */
    public function politicalOffices(): HasMany
    {
        return $this->hasMany(PoliticalOffice::class);
    }

    /**
     * The person's (current) person-of-interest record, if any.
     *
     * @return HasOne<PersonOfInterest, $this>
     */
    public function personOfInterest(): HasOne
    {
        return $this->hasOne(PersonOfInterest::class);
    }

    /**
     * @return HasMany<CriminalGroupMember, $this>
     */
    public function groupMemberships(): HasMany
    {
        return $this->hasMany(CriminalGroupMember::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
