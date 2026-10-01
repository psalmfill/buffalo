<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Jurisdiction\JurisdictionOptions;

/**
 * Threat reports (Phase 6B): permission, the report's own jurisdiction AND
 * classification. Never grantable across jurisdictions.
 *
 * `update` covers editing, status changes, links and extension. LGA chiefs
 * may manage only the threat reports they created. Linking also needs
 * `view` on the record linked.
 */
final class ThreatReportPolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
        private readonly JurisdictionOptions $options,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::ThreatsView->value);
    }

    public function view(User $actor, ThreatReport $threat): bool
    {
        return $this->viewAny($actor) && $this->reachable($actor, $threat);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::ThreatsCreate->value)
            && $this->options->lgaQuery($actor)->active()->exists();
    }

    public function update(User $actor, ThreatReport $threat): bool
    {
        return $actor->checkPermissionTo(Permission::ThreatsManage->value)
            && $this->reachable($actor, $threat)
            && ($actor->getRole() !== Role::LgaSecurityChief || $threat->created_by === $actor->id);
    }

    public function reactivate(User $actor, ThreatReport $threat): bool
    {
        return $actor->checkPermissionTo(Permission::ThreatsReactivate->value) && $this->reachable($actor, $threat);
    }

    public function delete(User $actor, ThreatReport $threat): bool
    {
        return $actor->checkPermissionTo(Permission::ThreatsDelete->value) && $this->reachable($actor, $threat);
    }

    public function linkFlashpoint(User $actor, ThreatReport $threat, Flashpoint $flashpoint): bool
    {
        return $this->update($actor, $threat) && $actor->can('view', $flashpoint);
    }

    public function linkGroup(User $actor, ThreatReport $threat, CriminalGroup $group): bool
    {
        return $this->update($actor, $threat) && $actor->can('view', $group);
    }

    public function linkIncident(User $actor, ThreatReport $threat, Incident $incident): bool
    {
        return $this->update($actor, $threat) && $actor->can('view', $incident);
    }

    private function reachable(User $actor, ThreatReport $threat): bool
    {
        return $this->jurisdiction->canAccessOwn($actor, $threat) && $this->classification->canSee($actor, $threat);
    }
}
