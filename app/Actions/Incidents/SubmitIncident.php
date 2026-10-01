<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\NotificationSeverity;
use App\Enums\Severity;
use App\Events\Incidents\IncidentEscalated;
use App\Events\Incidents\IncidentSubmitted;
use App\Models\Incident;
use App\Models\User;
use App\Support\Flashpoints\FlashpointAlerts;
use App\Support\Incidents\IncidentNotifier;
use App\Support\Incidents\IncidentReferenceAllocator;
use App\Support\Incidents\IncidentWorkflow;
use App\Support\Incidents\PossibleDuplicates;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Submits a draft or a returned incident (Phase 5C). Only the core facts are
 * needed (type, title, description of 20+ characters, severity, time, LGA):
 * the first report must reach the state and national level fast.
 *
 * - The reference number is assigned at the first submission and never
 *   changes.
 * - Possible duplicates must be confirmed ("Submit anyway"); they are
 *   recorded in the activity event.
 * - A `critical` incident is escalated automatically ("Critical severity").
 * - The SD and DSD are notified (severity follows the incident's); a
 *   critical one also reaches the DG and the DDGs covering the state.
 * - Inside the area of an active or monitoring flashpoint, the
 *   flashpoint's owners are told (FlashpointAlerts, Phase 6A).
 */
final class SubmitIncident
{
    public function __construct(
        private readonly IncidentWorkflow $workflow,
        private readonly IncidentReferenceAllocator $references,
        private readonly PossibleDuplicates $duplicates,
        private readonly IncidentNotifier $notifier,
        private readonly FlashpointAlerts $flashpoints,
    ) {}

    public function __invoke(Incident $incident, User $actor, bool $confirmDuplicates): Incident
    {
        $this->ensureCoreFacts($incident);

        $duplicates = $this->duplicates->for($incident);

        if ($duplicates->isNotEmpty() && ! $confirmDuplicates) {
            throw ValidationException::withMessages([
                'duplicates' => 'This incident may already have been reported. Review the possible duplicates, then submit anyway or cancel.',
            ]);
        }

        $critical = $incident->severity === Severity::Critical && ! $incident->isEscalated();
        $first = ! $incident->wasSubmitted();

        $submitted = $this->workflow->move(
            $incident,
            IncidentStatus::Submitted,
            $actor,
            ['submitted_by' => $actor->id],
            null,
            'submitted',
            $first ? 'Incident submitted' : 'Incident resubmitted',
            [
                'possible_duplicates' => $duplicates->map(fn (Incident $d) => ['incident' => $d->ulid, 'reference' => $d->reference_no])->values()->all(),
                'auto_escalated' => $critical,
            ],
            IncidentSubmitted::class,
            function (Incident $locked, CarbonImmutable $at) use ($critical): array {
                return [
                    'submitted_at' => $at,
                    ...($locked->reference_no === null ? ['reference_no' => $this->references->allocate($locked, $at)] : []),
                    ...($critical ? ['escalated_at' => $at, 'escalated_by' => null, 'escalation_reason' => 'Critical severity'] : []),
                ];
            },
        );

        if ($critical) {
            IncidentEscalated::dispatch($submitted, $this->workflow->stateUlid($submitted));
        }

        $this->notifier->send($submitted, 'submitted', $this->notifier->stateLeads($submitted), match ($submitted->severity) {
            Severity::Critical => NotificationSeverity::Critical,
            Severity::High => NotificationSeverity::Warning,
            default => NotificationSeverity::Info,
        });

        if ($critical) {
            $this->notifier->send($submitted, 'submitted_critical', $this->notifier->national($submitted), NotificationSeverity::Critical);
        }

        // Inside a flashpoint area (Phase 6A): its owners are told once.
        $this->flashpoints->incidentSubmitted($submitted);

        return $submitted;
    }

    private function ensureCoreFacts(Incident $incident): void
    {
        $errors = [];

        if (mb_strlen(trim($incident->description)) < 20) {
            $errors['description'] = 'Describe what happened in at least 20 characters before submitting.';
        }

        if (trim($incident->title) === '') {
            $errors['title'] = 'Give the incident a title before submitting.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
