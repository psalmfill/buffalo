<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use App\Support\Incidents\IncidentNotifier;
use App\Support\Incidents\IncidentWorkflow;

/**
 * Closes a resolved incident (Phase 5C). Closed incidents are locked (policies and database triggers); the reporting officer and the creator are told.
 */
final class CloseIncident
{
    public function __construct(
        private readonly IncidentWorkflow $workflow,
        private readonly IncidentNotifier $notifier,
    ) {}

    public function __invoke(Incident $incident, User $actor, ?string $comment): Incident
    {
        $moved = $this->workflow->move($incident, IncidentStatus::Closed, $actor, [
            'closed_by' => $actor->id,
            'closed_at' => now(),
        ], $comment, 'closed', 'Incident closed (locked)');

        $this->notifier->send($moved, 'closed', $this->notifier->officers($moved));

        return $moved;
    }
}
