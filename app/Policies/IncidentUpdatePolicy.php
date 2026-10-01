<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Incident follow-ups (Phase 5C): `incidents.view`, the follow-up's own
 * jurisdiction and classification, and its incident visible (so a
 * follow-up written before the incident was raised is seen only by those
 * who can see the incident now). Adding one is IncidentPolicy::addUpdate.
 */
final class IncidentUpdatePolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
    ) {}

    public function view(User $actor, IncidentUpdate $update): bool
    {
        /** @var Incident|null $incident */
        $incident = $update->incident;

        return $incident !== null
            && $actor->checkPermissionTo(Permission::IncidentsView->value)
            && $this->jurisdiction->canAccessOwn($actor, $update)
            && $this->classification->canSee($actor, $update)
            && $actor->can('view', $incident);
    }
}
