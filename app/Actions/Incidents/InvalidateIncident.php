<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\InvalidReason;
use App\Models\Incident;
use App\Models\User;
use App\Support\Incidents\IncidentNotifier;
use App\Support\Incidents\IncidentWorkflow;

/**
 * Marks a submitted or verified incident invalid (Phase 5C): a reason, a note for "other", the original for a duplicate. Invalid incidents are locked and left out of statistics; the reporting officer and the creator are told.
 */
final class InvalidateIncident
{
    public function __construct(
        private readonly IncidentWorkflow $workflow,
        private readonly IncidentNotifier $notifier,
    ) {}

    public function __invoke(Incident $incident, User $actor, InvalidReason $reason, ?string $note, ?Incident $duplicateOf): Incident
    {
        $moved = $this->workflow->move($incident, IncidentStatus::Invalid, $actor, [
            'invalidated_by' => $actor->id,
            'invalidated_at' => now(),
            'invalid_reason' => $reason,
            'invalid_note' => $note,
            'duplicate_of_incident_id' => $duplicateOf?->id,
        ], $reason->label().($note !== null ? ": {$note}" : ''), 'invalidated', 'Incident marked invalid (locked)', [
            'reason' => $reason->value,
            ...($duplicateOf !== null ? ['duplicate_of' => $duplicateOf->ulid, 'duplicate_of_reference' => $duplicateOf->reference_no] : []),
        ]);

        $this->notifier->send($moved, 'invalidated', $this->notifier->officers($moved));

        return $moved;
    }
}
