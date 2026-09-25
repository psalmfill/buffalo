<?php

namespace App\Support\ReferenceData;

use App\Models\ReferenceModel;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Reference values for forms and display.
 *
 * - options(): the ACTIVE values, cached (Valkey) until a value changes.
 * - find(): any value by ULID, active or not, so records keep showing
 *   values that were deactivated after they were chosen.
 */
final class ReferenceData
{
    private const PREFIX = 'reference_data:options:';

    public function __construct(private readonly Cache $cache) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function options(ReferenceTable $table): array
    {
        /** @var list<array<string, mixed>> */
        return $this->cache->rememberForever(self::PREFIX.$table->value, fn (): array => $table->query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ReferenceModel $value): array => $value->toOption())
            ->values()
            ->all());
    }

    public function find(ReferenceTable $table, string $ulid): ?ReferenceModel
    {
        return $table->query()->where('ulid', $ulid)->first();
    }

    public function forget(ReferenceTable $table): void
    {
        $this->cache->forget(self::PREFIX.$table->value);
    }
}
