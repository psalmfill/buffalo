<?php

namespace App\Events\Threats;

use App\Models\ThreatReport;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * Real-time threat events (Phase 6B), on `states.{state_ulid}.threats` and
 * `national.threats` (parallel to the incident channels, gated on
 * threats.view).
 *
 * PAYLOAD RULE (CLAUDE.md): the threat report ULID and the event name only.
 */
abstract class ThreatReportBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly string $threatUlid;

    public readonly string $stateUlid;

    public function __construct(ThreatReport $threat)
    {
        $this->threatUlid = $threat->ulid;
        $this->stateUlid = (string) DB::table('states')->where('id', $threat->state_id)->value('ulid');
    }

    abstract public function name(): string;

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("states.{$this->stateUlid}.threats"),
            new PrivateChannel('national.threats'),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->name();
    }

    /**
     * @return array{threat: string, event: string}
     */
    public function broadcastWith(): array
    {
        return ['threat' => $this->threatUlid, 'event' => $this->name()];
    }
}
