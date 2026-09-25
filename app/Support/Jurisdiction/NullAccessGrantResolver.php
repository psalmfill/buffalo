<?php

namespace App\Support\Jurisdiction;

use App\Models\User;
use App\Support\Jurisdiction\Contracts\AccessGrantResolver;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * No access grants exist until Phase 3.
 */
final class NullAccessGrantResolver implements AccessGrantResolver
{
    /**
     * @param  Builder<covariant Model>  $query
     */
    public function addGrantedRecords(Builder $query, Model&JurisdictionScoped $model, User $user): void {}

    public function hasGrant(User $user, Model&JurisdictionScoped $record): bool
    {
        return false;
    }
}
