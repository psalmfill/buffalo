<?php

namespace App\Support\Jurisdiction;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Names of officers by id (Phase 4A), for workflow records that cross
 * levels of the hierarchy: an LGA chief reads who at state level returned
 * or reviewed their report.
 *
 * SANCTIONED SCOPE BYPASS (inside App\Support\Jurisdiction): names only.
 * Users are jurisdiction-scoped, but naming who acted on a record the
 * viewer may already see reveals nothing else about those officers.
 */
final class OfficerNames
{
    /**
     * @param  iterable<int|null>  $ids
     * @return Collection<int, string> id => name
     */
    public static function for(iterable $ids): Collection
    {
        $ids = collect($ids)->filter(fn (mixed $id) => is_int($id))->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->withoutGlobalScope(JurisdictionScope::class)->withTrashed()
            ->whereKey($ids->all())
            ->pluck('name', 'id');
    }
}
