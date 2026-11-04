<?php

namespace App\Models;

use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Persons\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

/**
 * One of a person's phone numbers (Phase 11G.4). The number is encrypted
 * with CipherSweet and has a blind index (person_phone_bidx) for exact
 * matching only, stored as +234XXXXXXXXXX. Like aliases, phones are part
 * of the person record: shown only with a person the viewer may see (and
 * under a `full` grant), and never queried on their own except to find
 * which persons have a number (PersonPhones), whose results then go
 * through the persons' own scopes.
 *
 * The number never reaches the activity log; additions and removals are
 * logged on the person as "phone numbers changed" (PersonPhones).
 *
 * @property int $id
 * @property string $ulid
 * @property int $person_id
 * @property string $number +234XXXXXXXXXX (encrypted at rest)
 * @property string $label mobile, work, home or other
 * @property bool $is_primary
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property-read Person $person
 */
class PersonPhone extends Model implements CipherSweetEncrypted
{
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;
    use UsesCipherSweet;

    public const ENCRYPTED = ['number'];

    public const BLIND_INDEXES = ['number' => 'person_phone_bidx'];

    public const LABELS = ['mobile' => 'Mobile', 'work' => 'Work', 'home' => 'Home', 'other' => 'Other'];

    protected $fillable = ['number', 'label', 'is_primary'];

    protected $hidden = ['id', 'person_id', 'number', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow->addTextField('number');
        // Keyed hash, full 256-bit output: exact matches only (as persons).
        $encryptedRow->addBlindIndex('number', new BlindIndex('person_phone_bidx', [], 256, true));
    }

    /**
     * The blind index a number would have (exact match).
     */
    public static function blindIndexFor(string $number): string
    {
        $index = static::getCipherSweetEncryptedRow()->getBlindIndex('person_phone_bidx', ['number' => $number]);

        return is_string($index) ? $index : (string) $index['value'];
    }

    /**
     * @return array<int, string>
     */
    protected function auditExcept(): array
    {
        return self::ENCRYPTED;
    }

    /**
     * Stored and indexed only as +234XXXXXXXXXX.
     *
     * @return Attribute<string, string>
     */
    protected function number(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => PhoneNumber::normalise($value) ?? throw new InvalidArgumentException('Invalid phone number.'));
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
