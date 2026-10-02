<?php

namespace App\Services\Search\Providers;

use App\Models\User;
use App\Services\Search\SearchProvider;
use App\Services\Search\SearchQuery;
use Carbon\CarbonInterface;

/**
 * Shared helpers for global search providers (Phase 7B).
 */
abstract class BaseProvider implements SearchProvider
{
    abstract protected function permission(): string;

    public function allows(User $user): bool
    {
        return $user->checkPermissionTo($this->permission());
    }

    public function supports(SearchQuery $query): bool
    {
        return $query->isText();
    }

    /** "Kidnapping · Ikeja LGA · 3 days ago" (empty parts dropped). */
    protected static function subtitle(?string ...$parts): string
    {
        return implode(' · ', array_values(array_filter($parts, fn (?string $p) => $p !== null && $p !== '')));
    }

    protected static function ago(?CarbonInterface $at): ?string
    {
        return $at?->diffForHumans();
    }

    protected static function score(mixed $value): float
    {
        return max(0.0, min(1.0, (float) $value));
    }
}
