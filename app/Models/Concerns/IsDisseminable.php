<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\Jurisdiction\JurisdictionScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * For Disseminable models (Phase 7A): the jurisdiction scope also lets
 * through records disseminated to the viewer. Figures, pickers and link
 * targets must count or offer only the viewer's OWN records:
 * `->ownJurisdiction($user)`.
 */
trait IsDisseminable
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnJurisdiction(Builder $query, User $user): Builder
    {
        app(JurisdictionScope::class)->applyOwn($query, $this, $user);

        return $query;
    }
}
