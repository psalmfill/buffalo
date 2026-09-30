<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\OrganizationContact;
use App\Models\OrganizationSite;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Organization contacts (Phase 4B): permission and the contact's OWN
 * jurisdiction (access rule 9), and the site must be visible (which carries
 * the site's classification). Managed with `organizations.manage`.
 */
final class OrganizationContactPolicy
{
    public function __construct(private readonly JurisdictionGate $jurisdiction) {}

    public function view(User $actor, OrganizationContact $contact): bool
    {
        return $actor->checkPermissionTo(Permission::OrganizationsView->value)
            && $this->jurisdiction->canAccessOwn($actor, $contact)
            && ($site = $contact->getRelationValue('site') ?? $contact->site()->first()) instanceof OrganizationSite
            && $actor->can('view', $site);
    }

    public function update(User $actor, OrganizationContact $contact): bool
    {
        return $actor->checkPermissionTo(Permission::OrganizationsManage->value)
            && $this->jurisdiction->canAccessOwn($actor, $contact)
            && ($site = $contact->getRelationValue('site') ?? $contact->site()->first()) instanceof OrganizationSite
            && $actor->can('update', $site);
    }

    public function delete(User $actor, OrganizationContact $contact): bool
    {
        return $this->update($actor, $contact);
    }
}
