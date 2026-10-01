<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use App\Support\Incidents\IncidentWorkflow;

/**
 * Reopens a resolved incident with a reason, back to under investigation (Phase 5C).
 */
final class ReopenIncident
{
    public function __construct(private readonly IncidentWorkflow $workflow) {}

    public function __invoke(Incident $incident, User $actor, string $reason): Incident
    {
        return $this->workflow->move($incident, IncidentStatus::UnderInvestigation, $actor, [], $reason, 'reopened', 'Incident reopened');
    }
}
