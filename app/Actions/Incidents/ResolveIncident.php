<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use App\Support\Incidents\IncidentNotifier;
use App\Support\Incidents\IncidentWorkflow;

/**
 * Resolves an incident with a resolution summary (Phase 5C); the reporting officer and the creator are told.
 */
final class ResolveIncident
{
    public function __construct(
        private readonly IncidentWorkflow $workflow,
        private readonly IncidentNotifier $notifier,
    ) {}

    public function __invoke(Incident $incident, User $actor, string $summary): Incident
    {
        $moved = $this->workflow->move($incident, IncidentStatus::Resolved, $actor, [
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
            'resolution_summary' => $summary,
        ], $summary, 'resolved', 'Incident resolved');

        $this->notifier->send($moved, 'resolved', $this->notifier->officers($moved));

        return $moved;
    }
}
