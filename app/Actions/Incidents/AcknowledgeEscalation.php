<?php

namespace App\Actions\Incidents;

use App\Events\Incidents\IncidentUpdated;
use App\Models\Incident;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Incidents\IncidentNotifier;
use App\Support\Incidents\IncidentWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * Acknowledges an escalation at national level (Phase 5C): records who did
 * it (the status does not change) and tells the escalating officer.
 */
final class AcknowledgeEscalation
{
    public function __construct(
        private readonly IncidentWorkflow $workflow,
        private readonly IncidentNotifier $notifier,
    ) {}

    public function __invoke(Incident $incident, User $actor): Incident
    {
        $acknowledged = DB::transaction(function () use ($incident, $actor): Incident {
            $locked = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['escalation_acknowledged_at' => now(), 'escalation_acknowledged_by' => $actor->id])->save();
            AuditLog::event('escalation_acknowledged', 'Escalation acknowledged at national level', $locked, [], $actor);
            IncidentUpdated::dispatch($locked, $this->workflow->stateUlid($locked));

            return $locked;
        });

        // The escalating officer; an automatic escalation was raised by the submission.
        $escalator = $acknowledged->escalated_by ?? $acknowledged->submitted_by;
        $this->notifier->send($acknowledged, 'acknowledged', $this->notifier->users([$escalator]));

        return $acknowledged;
    }
}
