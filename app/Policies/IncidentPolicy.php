<?php

namespace App\Policies;

use App\Enums\IncidentStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Jurisdiction\JurisdictionOptions;

/**
 * Incidents (Phase 5A): permission, the incident's own jurisdiction AND
 * classification. Incidents are never grantable across jurisdictions.
 * Until the workflow (5C), only drafts are edited: by their creator, or by
 * the State Director or Deputy of the state.
 */
final class IncidentPolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
        private readonly JurisdictionOptions $options,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::IncidentsView->value);
    }

    public function view(User $actor, Incident $incident): bool
    {
        return $this->viewAny($actor) && $this->reachable($actor, $incident);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::IncidentsCreate->value)
            && $this->options->lgaQuery($actor)->active()->exists();
    }

    public function createIn(User $actor, Lga $lga): bool
    {
        return $actor->checkPermissionTo(Permission::IncidentsCreate->value)
            && $this->options->lgaQuery($actor)->active()->whereKey($lga->id)->exists();
    }

    public function update(User $actor, Incident $incident): bool
    {
        return $incident->status === IncidentStatus::Draft
            && $actor->checkPermissionTo(Permission::IncidentsUpdate->value)
            && $this->reachable($actor, $incident)
            && ($incident->created_by === $actor->id || $this->isStateLead($actor, $incident));
    }

    private function isStateLead(User $actor, Incident $incident): bool
    {
        return in_array($actor->getRole(), [Role::StateDirector, Role::DeputyStateDirector], true)
            && $actor->state_id === $incident->state_id;
    }

    private function reachable(User $actor, Incident $incident): bool
    {
        return $this->jurisdiction->canAccessOwn($actor, $incident) && $this->classification->canSee($actor, $incident);
    }
}
