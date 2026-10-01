<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use App\Support\Incidents\IncidentNotifier;
use App\Support\Incidents\IncidentWorkflow;

/**
 * Returns a submitted incident to the reporting side with a reason (Phase 5C); the reporting officer and the creator are told.
 */
final class ReturnIncident
{
    public function __construct(
        private readonly IncidentWorkflow $workflow,
        private readonly IncidentNotifier $notifier,
    ) {}

    public function __invoke(Incident $incident, User $actor, string $reason): Incident
    {
        $moved = $this->workflow->move($incident, IncidentStatus::Returned, $actor, [
            'returned_by' => $actor->id,
            'returned_at' => now(),
            'return_reason' => $reason,
        ], $reason, 'returned', 'Incident returned to the reporting officer');

        $this->notifier->send($moved, 'returned', $this->notifier->officers($moved));

        return $moved;
    }
}
