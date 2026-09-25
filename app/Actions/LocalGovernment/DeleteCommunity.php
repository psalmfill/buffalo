<?php

namespace App\Actions\LocalGovernment;

use App\Models\Community;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a community; the reason is recorded in the audit log.
 * Operational records are never hard-deleted (CLAUDE.md).
 */
final class DeleteCommunity
{
    public function __invoke(Community $community, string $reason, User $actor): void
    {
        DB::transaction(function () use ($community, $reason, $actor): void {
            $community->forceFill(['updated_by' => $actor->id])->saveQuietly();

            AuditLog::event('community_deleted', "Community {$community->name} deleted", $community, ['reason' => $reason], $actor);

            $community->delete();
        });
    }
}
