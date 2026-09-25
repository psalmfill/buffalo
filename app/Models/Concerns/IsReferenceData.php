<?php

namespace App\Models\Concerns;

use App\Support\ReferenceData\ReferenceData;
use App\Support\ReferenceData\ReferenceTable;
use LogicException;

/**
 * Administrator-managed lookup values (Phase 2A): ULID route key, audit
 * logging, `active()` scope. Values are deactivated, never deleted (records
 * reference them; a database trigger also rejects DELETE), and `code` is
 * fixed once created. Every change clears the cached form options.
 */
trait IsReferenceData
{
    use HasActiveScope;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    abstract public static function referenceTable(): ReferenceTable;

    public static function bootIsReferenceData(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Reference data is deactivated, never deleted.');
        });

        static::updating(function (self $model): void {
            if ($model->isDirty('code')) {
                throw new LogicException('A reference data code cannot be changed once created.');
            }
        });

        static::saved(fn () => app(ReferenceData::class)->forget(static::referenceTable()));
    }

    /**
     * @return array<string, string>
     */
    protected function referenceCasts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The value as a form option: ULID, name, code and the table's extra
     * fields. Never numeric ids.
     *
     * @return array<string, mixed>
     */
    public function toOption(): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'code' => $this->code,
            ...$this->optionExtras(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function optionExtras(): array
    {
        return [];
    }
}
