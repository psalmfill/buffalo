<?php

namespace App\Models;

use App\Support\ReferenceData\ReferenceTable;
use Database\Factories\PersonRoleTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * A role a person may hold (Phase 11A.1; the Phase 3A enum before). Reference
 * data: deactivated, never deleted; the code is fixed once created.
 *
 * System types keep behaviour tied to their codes (use the constants, never
 * string literals) and cannot be deactivated; administrators may rename
 * them and change their description and order:
 * - PERSON_OF_INTEREST: set only through the POI actions (Phase 3B), never
 *   offered in role pickers;
 * - POLITICAL_LEADER: added automatically with political offices (Phase 3B);
 * - TRADITIONAL_RULER, RELIGIOUS_LEADER: required for community and
 *   religious institution links (Phase 3A).
 *
 * Not jurisdiction-scoped: everyone reads reference data.
 *
 * @property bool $is_system
 */
class PersonRoleType extends ReferenceModel
{
    /** @use HasFactory<PersonRoleTypeFactory> */
    use HasFactory;

    public const POLITICAL_LEADER = 'political_leader';

    public const TRADITIONAL_RULER = 'traditional_ruler';

    public const RELIGIOUS_LEADER = 'religious_leader';

    public const COMMUNITY_LEADER = 'community_leader';

    public const PERSON_OF_INTEREST = 'person_of_interest';

    /** The system types: their codes are used by the application. */
    public const SYSTEM = [self::POLITICAL_LEADER, self::TRADITIONAL_RULER, self::RELIGIOUS_LEADER, self::COMMUNITY_LEADER, self::PERSON_OF_INTEREST];

    /**
     * Types shown with their own places on the Leadership pages (offices,
     * communities, religious institutions) or not leadership at all; the
     * rest are grouped as community-level leaders.
     */
    public const NOT_COMMUNITY_LEVEL = [self::POLITICAL_LEADER, self::TRADITIONAL_RULER, self::RELIGIOUS_LEADER, self::PERSON_OF_INTEREST];

    private const ID_CACHE = 'person_role_types:ids';

    protected $fillable = ['name', 'code', 'description', 'sort_order', 'is_active'];

    protected $hidden = ['id', 'created_by', 'updated_by'];

    protected static function booted(): void
    {
        static::saving(function (self $type): void {
            if ($type->is_system && ! $type->is_active) {
                throw new LogicException('A system role type cannot be deactivated.');
            }
        });

        static::saved(fn () => Cache::forget(self::ID_CACHE));
    }

    public static function referenceTable(): ReferenceTable
    {
        return ReferenceTable::PersonRoleTypes;
    }

    protected function casts(): array
    {
        return [
            ...$this->referenceCasts(),
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function optionExtras(): array
    {
        return ['is_system' => $this->is_system];
    }

    /**
     * Types that may be newly assigned through the persons forms: active,
     * and never the person-of-interest type.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('code', '!=', self::PERSON_OF_INTEREST);
    }

    /** The id of a type by code (cached; system codes always exist). */
    public static function idFor(string $code): int
    {
        $id = self::ids()->get($code);

        if ($id === null) {
            throw new LogicException("No person role type {$code}.");
        }

        return $id;
    }

    /**
     * @return Collection<string, int> code => id
     */
    public static function ids(): Collection
    {
        /** @var array<string, int> $ids */
        $ids = Cache::rememberForever(self::ID_CACHE, fn (): array => self::query()->pluck('id', 'code')->map(fn ($id) => (int) $id)->all());

        return collect($ids);
    }

    /**
     * For badges and filters: the type as {value: code, label: name}.
     *
     * @return array{value: string, label: string}
     */
    public function option(): array
    {
        return ['value' => $this->code, 'label' => $this->name];
    }
}
