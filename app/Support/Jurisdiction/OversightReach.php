<?php

namespace App\Support\Jurisdiction;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cross-jurisdiction oversight (Phase 3C): access requests, match events
 * and grants of the owning states a DG or DDG covers. Shared by the
 * oversight tab and its exports (Phase 11E).
 */
final class OversightReach
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  a query with an owning_state_id column
     * @return Builder<TModel>
     */
    public static function apply(User $user, Builder $query): Builder
    {
        $restriction = app(JurisdictionRules::class)->restrictionFor($user, visibleToSuperAdmin: false);

        return match (true) {
            ! $user->checkPermissionTo(Permission::AccessRequestsOversee->value) => $query->whereRaw('1 = 0'),
            $restriction->type === RestrictionType::Everything => $query,
            $restriction->type === RestrictionType::States => $query->whereIn($query->getModel()->qualifyColumn('owning_state_id'), $restriction->stateIds),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
