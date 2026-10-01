<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A dissemination was revoked or has expired (Phase 7A). Broadcast on each
 * recipient's private channel `users.{ulid}` so a page open through it
 * closes at once (as AccessGrantEnded does for person grants).
 *
 * PAYLOAD RULE: the dissemination ULID only.
 */
final class DisseminationEnded implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $disseminationUlid,
        public readonly string $holderUlid,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('users.'.$this->holderUlid);
    }

    public function broadcastAs(): string
    {
        return 'dissemination.ended';
    }

    /**
     * @return array{dissemination: string}
     */
    public function broadcastWith(): array
    {
        return ['dissemination' => $this->disseminationUlid];
    }
}
