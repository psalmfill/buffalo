<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use App\Support\Incidents\IncidentWorkflow;

/**
 * Starts the investigation of a verified incident (Phase 5C).
 */
final class StartInvestigation
{
    public function __construct(private readonly IncidentWorkflow $workflow) {}

    public function __invoke(Incident $incident, User $actor, ?string $comment): Incident
    {
        return $this->workflow->move($incident, IncidentStatus::UnderInvestigation, $actor, [
            'investigation_started_by' => $actor->id,
            'investigation_started_at' => now(),
        ], $comment, 'investigation_started', 'Investigation started');
    }
}
