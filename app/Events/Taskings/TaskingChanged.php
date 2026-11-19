<?php

namespace App\Events\Taskings;

use App\Models\Tasking;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * Real-time tasking events (Phase 11H), so dashboards and pages refresh:
 * on each recipient LGA chief's own channel (`users.{ulid}`) and the
 * state's tasking channel (`states.{ulid}.taskings`, the state's
 * directors and national users covering it; never LGA chiefs, who must not
 * learn of taskings to other LGAs).
 *
 * PAYLOAD RULE (CLAUDE.md): the tasking ULID and the event name only.
 */
final class TaskingChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly string $taskingUlid;

    public readonly string $stateUlid;

    /**
     * @param  list<string>  $userUlids  the recipient chiefs to tell
     */
    public function __construct(Tasking $tasking, public readonly string $event, public readonly array $userUlids = [])
    {
        $this->taskingUlid = $tasking->ulid;
        $this->stateUlid = (string) DB::table('states')->where('id', $tasking->state_id)->value('ulid');
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("states.{$this->stateUlid}.taskings"),
            ...array_map(fn (string $ulid) => new PrivateChannel("users.{$ulid}"), $this->userUlids),
        ];
    }

    public function broadcastAs(): string
    {
        return 'tasking.changed';
    }

    /**
     * @return array{tasking: string, event: string}
     */
    public function broadcastWith(): array
    {
        return ['tasking' => $this->taskingUlid, 'event' => $this->event];
    }
}
