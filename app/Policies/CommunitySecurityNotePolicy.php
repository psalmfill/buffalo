<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CommunitySecurityNote;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Community security notes (Phase 2C): every ability checks the permission,
 * jurisdiction AND classification (ClassificationGate), in depth behind
 * both scopes. Creating one is LgaPolicy::addSecurityNote; the level a
 * user may assign is checked by the request (ClassificationGate::canAssign).
 */
final class CommunitySecurityNotePolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::SecurityNotesView->value);
    }

    public function view(User $actor, CommunitySecurityNote $note): bool
    {
        return $this->viewAny($actor) && $this->reachable($actor, $note);
    }

    /** Edit, reclassify and change status. */
    public function update(User $actor, CommunitySecurityNote $note): bool
    {
        return $actor->checkPermissionTo(Permission::SecurityNotesManage->value) && $this->reachable($actor, $note);
    }

    public function delete(User $actor, CommunitySecurityNote $note): bool
    {
        return $actor->checkPermissionTo(Permission::SecurityNotesDelete->value) && $this->reachable($actor, $note);
    }

    private function reachable(User $actor, CommunitySecurityNote $note): bool
    {
        return $this->jurisdiction->canAccess($actor, $note) && $this->classification->canSee($actor, $note);
    }
}
