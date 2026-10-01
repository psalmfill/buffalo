<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use App\Support\Incidents\IncidentNotifier;
use App\Support\Incidents\IncidentWorkflow;

/**
 * Verifies a submitted incident (Phase 5C); the reporting officer and the creator are told.
 */
final class VerifyIncident
{
    public function __construct(
        private readonly IncidentWorkflow $workflow,
        private readonly IncidentNotifier $notifier,
    ) {}

    public function __invoke(Incident $incident, User $actor, ?string $comment): Incident
    {
        $moved = $this->workflow->move($incident, IncidentStatus::Verified, $actor, [
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ], $comment, 'verified', 'Incident verified');

        $this->notifier->send($moved, 'verified', $this->notifier->officers($moved));

        return $moved;
    }
}
