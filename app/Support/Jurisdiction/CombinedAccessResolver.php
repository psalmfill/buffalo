<?php

namespace App\Support\Jurisdiction;

use App\Models\User;
use App\Support\Jurisdiction\Contracts\AccessGrantResolver;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The bound AccessGrantResolver: person access grants (Phase 3C) and
 * dissemination of threat reports and flashpoints (Phase 7A). Each part
 * acts on its own models only; a record passes when either allows it.
 */
final class CombinedAccessResolver implements AccessGrantResolver
{
    public function __construct(
        private readonly GrantAccessResolver $grants,
        private readonly DisseminationResolver $disseminations,
    ) {}

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function addGrantedRecords(Builder $query, Model&JurisdictionScoped $model, User $user): void
    {
        $this->grants->addGrantedRecords($query, $model, $user);
        $this->disseminations->addGrantedRecords($query, $model, $user);
    }

    public function hasGrant(User $user, Model&JurisdictionScoped $record): bool
    {
        return $this->grants->hasGrant($user, $record) || $this->disseminations->hasGrant($user, $record);
    }
}
