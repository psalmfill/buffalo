<?php

namespace App\Events;

use App\Models\RecordAccessGrant;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A cross-jurisdiction grant was revoked or has expired (Phase 4A.0).
 * Broadcast on the holder's private channel `users.{ulid}` so a person page
 * open under that grant closes at once.
 *
 * PAYLOAD RULE: the grant ULID only. Nothing about the person or the owning
 * jurisdiction is sent.
 */
final class AccessGrantEnded implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly string $grantUlid;

    public readonly string $holderUlid;

    public function __construct(RecordAccessGrant $grant, string $holderUlid)
    {
        $this->grantUlid = $grant->ulid;
        $this->holderUlid = $holderUlid;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('users.'.$this->holderUlid);
    }

    public function broadcastAs(): string
    {
        return 'access-grant.ended';
    }

    /**
     * @return array{grant: string}
     */
    public function broadcastWith(): array
    {
        return ['grant' => $this->grantUlid];
    }
}
