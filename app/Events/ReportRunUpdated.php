<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A report run changed status (Phase 7D). Broadcast on the requester's
 * private channel `users.{ulid}`; "My reports" refetches through the
 * normal routes.
 *
 * PAYLOAD RULE: the run ULID and status only.
 */
final class ReportRunUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $runUlid,
        public readonly string $status,
        public readonly string $requesterUlid,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('users.'.$this->requesterUlid);
    }

    public function broadcastAs(): string
    {
        return 'report.updated';
    }

    /**
     * @return array{run: string, status: string}
     */
    public function broadcastWith(): array
    {
        return ['run' => $this->runUlid, 'status' => $this->status];
    }
}
