<?php

namespace App\Actions\CommunityInformation;

use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes an intelligence record, recording the reason as a named
 * audit event ("{type}_deleted"). Operational records are never
 * hard-deleted (CLAUDE.md).
 */
final class DeleteWithReason
{
    public function __invoke(Model $record, string $type, string $reason, User $actor): void
    {
        DB::transaction(function () use ($record, $type, $reason, $actor): void {
            $record->forceFill(['updated_by' => $actor->id])->saveQuietly();

            AuditLog::event("{$type}_deleted", str_replace('_', ' ', ucfirst($type)).' deleted', $record, ['reason' => $reason], $actor);

            $record->delete();
        });
    }
}
