<?php

namespace App\Policies;

use App\Enums\IncidentStatus;
use App\Enums\Permission;
use App\Models\Arrest;
use App\Models\Incident;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Jurisdiction\JurisdictionOptions;

/**
 * Arrests (Phase 11C): permission, the arrest's own jurisdiction AND
 * classification. Never granted or disseminated, so every ability is
 * own-jurisdiction. `update` covers editing, arrested persons and status
 * changes. Linking an incident needs `view` on it, and never an invalid one.
 */
final class ArrestPolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
        private readonly JurisdictionOptions $options,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::ArrestsView->value);
    }

    public function view(User $actor, Arrest $arrest): bool
    {
        return $this->viewAny($actor) && $this->reachable($actor, $arrest);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::ArrestsCreate->value)
            && $this->options->lgaQuery($actor)->active()->exists();
    }

    public function update(User $actor, Arrest $arrest): bool
    {
        return $actor->checkPermissionTo(Permission::ArrestsManage->value) && $this->reachable($actor, $arrest);
    }

    public function delete(User $actor, Arrest $arrest): bool
    {
        return $actor->checkPermissionTo(Permission::ArrestsDelete->value) && $this->reachable($actor, $arrest);
    }

    /** Link an arrest to an incident: one the actor can see, not invalid. */
    public function linkIncident(User $actor, Incident $incident): bool
    {
        return $this->create($actor)
            && $actor->can('view', $incident)
            && $incident->status !== IncidentStatus::Invalid;
    }

    private function reachable(User $actor, Arrest $arrest): bool
    {
        return $this->jurisdiction->canAccessOwn($actor, $arrest) && $this->classification->canSee($actor, $arrest);
    }
}
