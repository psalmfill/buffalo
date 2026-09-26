<?php

namespace App\Support\ReferenceData;

use App\Support\ReferenceData\Contracts\ReferenceUsage;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Whether a lookup value is in use, from checks each module registers for
 * the lookup tables it references (Phase 3A). Phases 5 and 6 register
 * incident types, agencies, property types and threat types the same way,
 * without editing this class.
 *
 * "In use" is a system-level yes/no across all jurisdictions, and counts
 * soft-deleted records too (they can be restored). The checks read tables
 * directly, so no record content is ever loaded.
 */
final class ReferenceUsageRegistry implements ReferenceUsage
{
    /**
     * @var array<class-string<Model>, list<Closure(Model): bool>>
     */
    private array $checks = [];

    /**
     * @param  class-string<Model>  $lookup
     * @param  Closure(Model): bool  $check
     */
    public function register(string $lookup, Closure $check): void
    {
        $this->checks[$lookup][] = $check;
    }

    /**
     * The value is in use when any row of $table references it in $column.
     *
     * @param  class-string<Model>  $lookup
     */
    public function column(string $lookup, string $table, string $column): void
    {
        $this->register($lookup, fn (Model $value): bool => DB::table($table)->where($column, $value->getKey())->exists());
    }

    /**
     * The value is in use when any row of $table lists it in the jsonb
     * array $column, by $attribute (e.g. persons_of_interest.offence_categories
     * holds threat type codes, Phase 3B).
     *
     * @param  class-string<Model>  $lookup
     */
    public function jsonArray(string $lookup, string $table, string $column, string $attribute = 'id'): void
    {
        $this->register($lookup, fn (Model $value): bool => DB::table($table)
            ->whereJsonContains($column, $value->getAttribute($attribute))
            ->exists());
    }

    public function isInUse(Model $value): bool
    {
        foreach ($this->checks[$value::class] ?? [] as $check) {
            if ($check($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lookup models with at least one registered check.
     *
     * @return list<class-string<Model>>
     */
    public function lookups(): array
    {
        return array_keys($this->checks);
    }
}
