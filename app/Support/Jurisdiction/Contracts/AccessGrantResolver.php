<?php

namespace App\Support\Jurisdiction\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Extension point for cross-jurisdiction access grants (Phase 3).
 *
 * The scope calls addGrantedRecords() INSIDE its nested where group, after
 * the jurisdiction condition, so implementations must only add `orWhere...`
 * clauses that match records the user holds an active grant for. The gate
 * calls hasGrant() for single records. Both must agree.
 */
interface AccessGrantResolver
{
    /**
     * @param  Builder<covariant Model>  $query  the scope's nested group
     */
    public function addGrantedRecords(Builder $query, Model&JurisdictionScoped $model, User $user): void;

    public function hasGrant(User $user, Model&JurisdictionScoped $record): bool;
}
