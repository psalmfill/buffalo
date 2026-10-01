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
 * Workflow abilities (Phase 5C) combine a permission, the status, the
 * incident's own jurisdiction and classification, and the "who" rules.
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

    /**
     * Editing (Phase 5C): until verified, the creator, the reporting officer
     * and the SD/DSD of the state; from verified until closed, holders of
     * `incidents.amend` (with an amendment reason). Closed and invalid
     * incidents are locked.
     */
    public function update(User $actor, Incident $incident): bool
    {
        if ($incident->isLocked() || ! $this->reachable($actor, $incident)) {
            return false;
        }

        if (! $incident->requiresAmendmentReason()) {
            return $actor->checkPermissionTo(Permission::IncidentsUpdate->value) && $this->isOwnOrStateLead($actor, $incident);
        }

        return $actor->checkPermissionTo(Permission::IncidentsAmend->value);
    }

    /** After submission the LGA is corrected only by the State Director and above. */
    public function changeLga(User $actor, Incident $incident): bool
    {
        return ! $incident->wasSubmitted()
            || in_array($actor->getRole(), [Role::StateDirector, Role::DeputyDirectorGeneral, Role::DirectorGeneral], true);
    }

    public function submit(User $actor, Incident $incident): bool
    {
        return in_array($incident->status, [IncidentStatus::Draft, IncidentStatus::Returned], true)
            && $actor->checkPermissionTo(Permission::IncidentsSubmit->value)
            && $this->reachable($actor, $incident)
            && $this->isOwnOrStateLead($actor, $incident);
    }

    public function verify(User $actor, Incident $incident): bool
    {
        return $this->transition($actor, $incident, Permission::IncidentsVerify, [IncidentStatus::Submitted]);
    }

    public function return(User $actor, Incident $incident): bool
    {
        return $this->transition($actor, $incident, Permission::IncidentsVerify, [IncidentStatus::Submitted]);
    }

    public function startInvestigation(User $actor, Incident $incident): bool
    {
        return $this->transition($actor, $incident, Permission::IncidentsInvestigate, [IncidentStatus::Verified]);
    }

    public function resolve(User $actor, Incident $incident): bool
    {
        return $this->transition($actor, $incident, Permission::IncidentsInvestigate, [IncidentStatus::Verified, IncidentStatus::UnderInvestigation]);
    }

    public function reopen(User $actor, Incident $incident): bool
    {
        return $this->transition($actor, $incident, Permission::IncidentsClose, [IncidentStatus::Resolved]);
    }

    public function close(User $actor, Incident $incident): bool
    {
        return $this->transition($actor, $incident, Permission::IncidentsClose, [IncidentStatus::Resolved]);
    }

    public function invalidate(User $actor, Incident $incident): bool
    {
        return $this->transition($actor, $incident, Permission::IncidentsInvalidate, [IncidentStatus::Submitted, IncidentStatus::Verified]);
    }

    public function escalate(User $actor, Incident $incident): bool
    {
        return ! $incident->isEscalated()
            && $this->transition($actor, $incident, Permission::IncidentsEscalate, [IncidentStatus::Submitted, IncidentStatus::Verified, IncidentStatus::UnderInvestigation]);
    }

    /** The DG, or a DDG covering the state (jurisdiction); status unchanged. */
    public function acknowledge(User $actor, Incident $incident): bool
    {
        return $incident->isEscalated()
            && $incident->escalation_acknowledged_at === null
            && ! $incident->isLocked()
            && $actor->checkPermissionTo(Permission::IncidentsAcknowledge->value)
            && $this->reachable($actor, $incident);
    }

    /** Follow-ups: any status but closed and invalid. */
    public function addUpdate(User $actor, Incident $incident): bool
    {
        return ! $incident->isLocked()
            && $actor->checkPermissionTo(Permission::IncidentsUpdate->value)
            && $this->reachable($actor, $incident);
    }

    /**
     * @param  list<IncidentStatus>  $from
     */
    private function transition(User $actor, Incident $incident, Permission $permission, array $from): bool
    {
        return in_array($incident->status, $from, true)
            && $actor->checkPermissionTo($permission->value)
            && $this->reachable($actor, $incident);
    }

    private function isOwnOrStateLead(User $actor, Incident $incident): bool
    {
        return $incident->created_by === $actor->id
            || $incident->reporting_officer_id === $actor->id
            || $this->isStateLead($actor, $incident);
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
