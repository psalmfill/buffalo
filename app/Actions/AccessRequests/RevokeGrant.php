<?php

namespace App\Actions\AccessRequests;

use App\Models\RecordAccessGrant;
use App\Models\User;
use App\Notifications\AccessGrantChanged;
use App\Support\Audit\AuditLog;
use App\Support\Jurisdiction\AccessRequestParties;
use Illuminate\Support\Facades\DB;

/**
 * Revokes a grant with a reason (Phase 3C). Takes effect at once: the
 * resolver only lets active grants through the scope. The holder is told.
 */
final class RevokeGrant
{
    public function __invoke(RecordAccessGrant $grant, User $actor, string $reason): void
    {
        DB::transaction(function () use ($grant, $actor, $reason): void {
            $grant->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revocation_reason' => trim($reason),
            ])->save();

            AuditLog::event('access_grant_revoked', 'Temporary access revoked', $grant->request, ['reason' => trim($reason), 'grant' => $grant->ulid], $actor);
        });

        AccessRequestParties::attach($grant);
        $grant->user->notify(new AccessGrantChanged($grant->request->ulid, 'revoked', $grant->expires_at->toDayDateTimeString()));
    }
}
