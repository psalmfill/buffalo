<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CommunitySecurityNote;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Jurisdiction\JurisdictionOptions;

/**
 * Flashpoints and blackspots (Phase 6A): permission, the flashpoint's own
 * jurisdiction AND classification. Never grantable to individuals; disseminable (Phase 7A):
 * `view` also passes through an active dissemination, read-only.
 *
 * Linking needs flashpoints.manage on the flashpoint AND `view` on the
 * record linked (an incident or a security note), so a link never points
 * at something the linker cannot see. Which incidents may be linked
 * (not drafts or invalid ones) is checked by the request.
 */
final class FlashpointPolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
        private readonly JurisdictionOptions $options,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::FlashpointsView->value);
    }

    public function view(User $actor, Flashpoint $flashpoint): bool
    {
        // Own jurisdiction OR an active dissemination to the viewer (Phase 7A);
        // classification is always checked. Every other ability is own-only.
        return $this->viewAny($actor)
            && $this->jurisdiction->canAccess($actor, $flashpoint)
            && $this->classification->canSee($actor, $flashpoint);
    }

    /**
     * Reference this flashpoint from another record (a threat report link):
     * own jurisdiction only, never through a dissemination (Phase 7A).
     */
    public function link(User $actor, Flashpoint $flashpoint): bool
    {
        return $this->viewAny($actor) && $this->reachable($actor, $flashpoint);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::FlashpointsManage->value)
            && $this->options->lgaQuery($actor)->active()->exists();
    }

    /** Edit the description, place, area and classification (not the assessed fields). */
    public function update(User $actor, Flashpoint $flashpoint): bool
    {
        return $actor->checkPermissionTo(Permission::FlashpointsManage->value) && $this->reachable($actor, $flashpoint);
    }

    /** Record an assessment: the only way to change concern, status and review date. */
    public function assess(User $actor, Flashpoint $flashpoint): bool
    {
        return $actor->checkPermissionTo(Permission::FlashpointsAssess->value) && $this->reachable($actor, $flashpoint);
    }

    public function delete(User $actor, Flashpoint $flashpoint): bool
    {
        return $actor->checkPermissionTo(Permission::FlashpointsDelete->value) && $this->reachable($actor, $flashpoint);
    }

    /** Link or unlink an incident. */
    public function linkIncident(User $actor, Flashpoint $flashpoint, Incident $incident): bool
    {
        return $this->update($actor, $flashpoint) && $actor->can('view', $incident);
    }

    /** Link or unlink a community security note. */
    public function linkSecurityNote(User $actor, Flashpoint $flashpoint, CommunitySecurityNote $note): bool
    {
        return $this->update($actor, $flashpoint) && $actor->can('view', $note);
    }

    private function reachable(User $actor, Flashpoint $flashpoint): bool
    {
        return $this->jurisdiction->canAccessOwn($actor, $flashpoint) && $this->classification->canSee($actor, $flashpoint);
    }
}
