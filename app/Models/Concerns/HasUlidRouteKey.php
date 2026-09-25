<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Bigint `id` stays the primary key; a separate `ulid` column is generated on
 * create and used for route model binding (CLAUDE.md: never expose numeric
 * IDs). Route binding rejects values that are not valid ULIDs with a 404.
 */
trait HasUlidRouteKey
{
    use HasUlids;

    /**
     * Columns that receive a generated ULID on create.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
