<?php

namespace App\Events\Incidents;

use App\Models\Incident;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Real-time incident events (Phase 5C), on the incident's state channel
 * `states.{state_ulid}.incidents` and on `national.incidents`.
 *
 * PAYLOAD RULE (CLAUDE.md): the incident ULID and the event name only.
 * Clients refetch through the normal scoped routes, so jurisdiction and
 * classification still decide what each viewer sees.
 */
abstract class IncidentBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly string $incidentUlid;

    public readonly string $stateUlid;

    public function __construct(Incident $incident, string $stateUlid)
    {
        $this->incidentUlid = $incident->ulid;
        $this->stateUlid = $stateUlid;
    }

    abstract public function name(): string;

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("states.{$this->stateUlid}.incidents"),
            new PrivateChannel('national.incidents'),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->name();
    }

    /**
     * @return array{incident: string, event: string}
     */
    public function broadcastWith(): array
    {
        return ['incident' => $this->incidentUlid, 'event' => $this->name()];
    }
}
