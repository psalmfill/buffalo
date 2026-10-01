<?php

namespace App\Policies;

use App\Enums\IncidentStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Incident evidence (Phase 5B): `incidents.view`, the item's own
 * jurisdiction and classification, and the incident itself visible.
 * Evidence is never grantable across jurisdictions.
 *
 * - Upload: `evidence.upload` on an incident the user can update (a draft),
 *   and, once submitted (5C), on any incident they can view until it is
 *   closed.
 * - Metadata edits: the uploader, or the State Director or Deputy of the
 *   state, until the incident is closed.
 * - Download of the original, verification and deletion each need their
 *   own permission.
 */
final class IncidentEvidencePolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
    ) {}

    public function view(User $actor, IncidentEvidence $evidence): bool
    {
        // The relation is scoped: an incident out of reach loads as null.
        /** @var Incident|null $incident */
        $incident = $evidence->incident;

        return $incident !== null
            && $actor->checkPermissionTo(Permission::IncidentsView->value)
            && $this->jurisdiction->canAccessOwn($actor, $evidence)
            && $this->classification->canSee($actor, $evidence)
            && $actor->can('view', $incident);
    }

    public function upload(User $actor, Incident $incident): bool
    {
        if (! $actor->checkPermissionTo(Permission::EvidenceUpload->value) || $incident->status === IncidentStatus::Closed) {
            return false;
        }

        return $incident->status === IncidentStatus::Draft
            ? $actor->can('update', $incident)
            : $actor->can('view', $incident);
    }

    public function update(User $actor, IncidentEvidence $evidence): bool
    {
        return $this->view($actor, $evidence)
            && $actor->checkPermissionTo(Permission::EvidenceUpload->value)
            && $evidence->incident->status !== IncidentStatus::Closed
            && ($evidence->uploaded_by === $actor->id || $this->isStateLead($actor, $evidence));
    }

    public function download(User $actor, IncidentEvidence $evidence): bool
    {
        return $actor->checkPermissionTo(Permission::EvidenceDownloadOriginal->value) && $this->view($actor, $evidence);
    }

    public function verify(User $actor, IncidentEvidence $evidence): bool
    {
        return $actor->checkPermissionTo(Permission::EvidenceVerify->value) && $this->view($actor, $evidence);
    }

    public function delete(User $actor, IncidentEvidence $evidence): bool
    {
        return $actor->checkPermissionTo(Permission::EvidenceDelete->value) && $this->view($actor, $evidence);
    }

    private function isStateLead(User $actor, IncidentEvidence $evidence): bool
    {
        return in_array($actor->getRole(), [Role::StateDirector, Role::DeputyStateDirector], true)
            && $actor->state_id === $evidence->state_id;
    }
}
