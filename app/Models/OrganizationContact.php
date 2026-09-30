<?php

namespace App\Models;

use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Audit\AuditLog;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use App\Support\Persons\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

/**
 * A contact at an organization site (Phase 4B). Carries its own state_id
 * and lga_id (kept equal to the site's by a composite FK) and is scoped on
 * them (access rule 9): never visible through the site or organization
 * alone, and only while its site is visible (the site's classification). Every view of contact details is access-logged.
 *
 * Phone and email are encrypted with CipherSweet (ciphertext only, enforced
 * by CHECKs) and searchable only by exact match through blind indexes
 * (phone_bidx, email_bidx). They never reach the activity log.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_site_id
 * @property int $state_id
 * @property int $lga_id
 * @property string $name
 * @property string $position
 * @property string|null $phone +234XXXXXXXXXX (encrypted at rest)
 * @property string|null $email lower case (encrypted at rest)
 * @property bool $is_management
 * @property bool $is_security_contact
 * @property bool $is_primary
 * @property int|null $person_id
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read OrganizationSite $site
 * @property-read Person|null $person
 */
class OrganizationContact extends Model implements CipherSweetEncrypted, JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;
    use UsesCipherSweet {
        encryptRow as cipherSweetEncryptRow;
    }

    /** Encrypted columns. */
    public const ENCRYPTED = ['phone', 'email'];

    /** Blind index name per encrypted column (exact-match search). */
    public const BLIND_INDEXES = ['phone' => 'phone_bidx', 'email' => 'email_bidx'];

    protected $fillable = ['name', 'position', 'phone', 'email', 'is_management', 'is_security_contact', 'is_primary', 'notes'];

    protected $hidden = ['id', 'organization_site_id', 'state_id', 'lga_id', 'person_id', 'phone', 'email', 'created_by', 'updated_by'];

    protected $attributes = [
        'is_management' => false,
        'is_security_contact' => false,
        'is_primary' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_management' => 'boolean',
            'is_security_contact' => 'boolean',
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Also only while the site is visible (which adds the site's
        // classification); the contact's own jurisdiction applies as well.
        static::addGlobalScope('site', fn (Builder $query) => $query->whereHas('site'));
        static::created(fn (OrganizationContact $contact) => $contact->logProtectedFieldChanges(created: true));
        static::updated(fn (OrganizationContact $contact) => $contact->logProtectedFieldChanges(created: false));
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        foreach (self::ENCRYPTED as $column) {
            $encryptedRow->addOptionalTextField($column);
        }

        foreach (self::BLIND_INDEXES as $column => $name) {
            $encryptedRow->addBlindIndex($column, new BlindIndex($name, [], 256, true));
        }
    }

    /** Never save a partially loaded contact (the encrypted columns would be lost). */
    public function encryptRow(): void
    {
        if ($this->exists) {
            foreach (self::ENCRYPTED as $column) {
                if (! array_key_exists($column, $this->getAttributes())) {
                    throw new LogicException("A contact must be saved with its encrypted columns loaded ({$column} is missing).");
                }
            }
        }

        $this->cipherSweetEncryptRow();
    }

    /** Blind indexes only for present values; a cleared value's index is removed. */
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
     * Normalised like person phone numbers: +234XXXXXXXXXX.
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
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value === null || trim($value) === '' ? null : mb_strtolower(trim($value)));
    }

    /**
     * Encrypted values never reach the activity log.
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return self::ENCRYPTED;
    }

    /** Records which protected fields a save set or changed, never their values. */
    private function logProtectedFieldChanges(bool $created): void
    {
        $dirty = $this->cipherSweetSavingUnencryptedAttributes;
        $fields = array_values(array_filter(
            self::ENCRYPTED,
            fn (string $column): bool => array_key_exists($column, $dirty) && ! ($created && ($dirty[$column] === null || $dirty[$column] === '')),
        ));

        if ($fields !== []) {
            AuditLog::event(
                'protected_fields_changed',
                ($created ? 'Protected fields recorded: ' : 'Protected fields changed: ').implode(', ', $fields),
                $this,
                ['fields' => $fields],
            );
        }
    }

    /**
     * @return BelongsTo<OrganizationSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(OrganizationSite::class, 'organization_site_id');
    }

    /**
     * The linked person, when the viewer can see them (the person's scopes apply).
     *
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
