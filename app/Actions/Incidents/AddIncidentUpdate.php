<?php

namespace App\Actions\Incidents;

use App\Enums\Classification;
use App\Enums\IncidentUpdateType;
use App\Events\Incidents\IncidentUpdated;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\User;
use App\Support\Incidents\IncidentNotifier;
use App\Support\Incidents\IncidentWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * Adds a follow-up to an incident (Phase 5C). Follow-ups are append-only;
 * a correction is another follow-up.
 *
 * Notified (each only if they can see this follow-up): the reporting
 * officer and the SD/DSD of the state, not the author; for intelligence or
 * action taken on an escalated incident, also the DG and the DDGs covering
 * the state.
 */
final class AddIncidentUpdate
{
    public function __construct(
        private readonly IncidentWorkflow $workflow,
        private readonly IncidentNotifier $notifier,
    ) {}

    public function __invoke(Incident $incident, User $actor, IncidentUpdateType $type, string $body, Classification $classification): IncidentUpdate
    {
        $update = DB::transaction(function () use ($incident, $actor, $type, $body, $classification): IncidentUpdate {
            $update = new IncidentUpdate;
            $update->forceFill([
                'incident_id' => $incident->id,
                'state_id' => $incident->state_id,
                'lga_id' => $incident->lga_id,
                'type' => $type,
                'body' => $body,
                'classification' => $classification,
                'created_by' => $actor->id,
            ])->save();

            IncidentUpdated::dispatch($incident, $this->workflow->stateUlid($incident));

            return $update;
        });

        $this->notifier->send($incident, 'update_added', $this->notifier->users([$incident->reporting_officer_id])->merge($this->notifier->stateLeads($incident)), record: $update, except: [$actor->id]);

        if ($incident->isEscalated() && in_array($type, [IncidentUpdateType::Intelligence, IncidentUpdateType::ActionTaken], true)) {
            $this->notifier->send($incident, 'escalated_update', $this->notifier->national($incident), record: $update, except: [$actor->id]);
        }

        return $update;
    }
}
