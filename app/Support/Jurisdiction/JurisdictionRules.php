<?php

namespace App\Support\Jurisdiction;

use App\Enums\Role;
use App\Models\State;
use App\Models\User;

/**
 * The single source of truth for what a user's jurisdiction covers
 * (CLAUDE.md section 4). Everything fails closed: a blocked account, a
 * missing role, or a role whose jurisdiction data is inconsistent all
 * result in "nothing".
 */
final class JurisdictionRules
{
    /**
     * @param  bool  $visibleToSuperAdmin  whether the records concerned are
     *                                     system-administration data a super
     *                                     admin may see (e.g. users)
     */
    public function restrictionFor(User $user, bool $visibleToSuperAdmin): JurisdictionRestriction
    {
        if ($user->isAccessBlocked()) {
            return JurisdictionRestriction::nothing();
        }

        $role = $user->getRole();

        // Role and stored jurisdiction must agree; otherwise deny.
        if ($role === null || $role->jurisdictionLevel() !== $user->jurisdiction_level) {
            return JurisdictionRestriction::nothing();
        }

        return match ($role) {
            Role::SuperAdmin => $visibleToSuperAdmin
                ? JurisdictionRestriction::everything()
                : JurisdictionRestriction::nothing(),

            Role::DirectorGeneral => JurisdictionRestriction::everything(),

            Role::DeputyDirectorGeneral => $this->ddgRestriction($user),

            Role::StateDirector, Role::DeputyStateDirector => $user->state_id !== null
                ? JurisdictionRestriction::states([$user->state_id])
                : JurisdictionRestriction::nothing(),

            Role::LgaSecurityChief => $user->lga_id !== null
                ? JurisdictionRestriction::lga($user->lga_id)
                : JurisdictionRestriction::nothing(),
        };
    }

    private function ddgRestriction(User $user): JurisdictionRestriction
    {
        $zoneIds = $user->zones->modelKeys();

        if ($zoneIds === []) {
            return JurisdictionRestriction::everything();
        }

        /** @var list<int> $stateIds */
        $stateIds = State::query()->whereIn('geopolitical_zone_id', $zoneIds)->pluck('id')->all();

        // Zones with no states would otherwise widen to "everything".
        return JurisdictionRestriction::states($stateIds);
    }
}
