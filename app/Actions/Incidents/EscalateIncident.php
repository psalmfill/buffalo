<?php

namespace App\Actions\Incidents;

use App\Enums\NotificationSeverity;
use App\Enums\Severity;
use App\Events\Incidents\IncidentEscalated;
use App\Models\Incident;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Incidents\IncidentNotifier;
use App\Support\Incidents\IncidentWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * Escalates an incident to national level (Phase 5C): a flag, independent
 * of status. The DG and the DDGs covering the state are told (warning, or
 * critical for a critical incident).
 */
final class EscalateIncident
{
    public function __construct(
        private readonly IncidentWorkflow $workflow,
        private readonly IncidentNotifier $notifier,
    ) {}

    public function __invoke(Incident $incident, User $actor, string $reason): Incident
    {
        $escalated = DB::transaction(function () use ($incident, $actor, $reason): Incident {
            $locked = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['escalated_at' => now(), 'escalated_by' => $actor->id, 'escalation_reason' => $reason, 'updated_by' => $actor->id])->save();
            AuditLog::event('escalated', 'Incident escalated to national level', $locked, ['reason' => $reason], $actor);
            IncidentEscalated::dispatch($locked, $this->workflow->stateUlid($locked));

            return $locked;
        });

        $this->notifier->send(
            $escalated,
            'escalated',
            $this->notifier->national($escalated),
            $escalated->severity === Severity::Critical ? NotificationSeverity::Critical : NotificationSeverity::Warning,
        );

        return $escalated;
    }
}
