<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Lga;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;

/**
 * LGA pages and LGA-level intelligence (profiles, communities of an LGA).
 * LGAs are reference data and not scoped, so every ability also checks that
 * the LGA lies within the actor's jurisdiction. Super admins hold none of
 * these permissions. Blocked actors are denied by Gate::before.
 */
final class LgaPolicy
{
    public function __construct(private readonly JurisdictionOptions $jurisdiction) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::LgasView->value);
    }

    public function view(User $actor, Lga $lga): bool
    {
        return $this->viewAny($actor) && $this->jurisdiction->coversLga($actor, $lga);
    }

    /** Edit the profile and basic facts, and mark the profile as reviewed. */
    public function updateProfile(User $actor, Lga $lga): bool
    {
        return $actor->checkPermissionTo(Permission::LgaProfilesUpdate->value)
            && $this->jurisdiction->coversLga($actor, $lga);
    }

    public function addCommunity(User $actor, Lga $lga): bool
    {
        return $actor->checkPermissionTo(Permission::CommunitiesManage->value)
            && $this->jurisdiction->coversLga($actor, $lga);
    }

    /** Open the import page at all (the LGA is chosen there). */
    public function importAnyCommunities(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::CommunitiesImport->value);
    }

    public function importCommunities(User $actor, Lga $lga): bool
    {
        return $this->importAnyCommunities($actor) && $this->jurisdiction->coversLga($actor, $lga);
    }
}
