<?php

namespace App\Actions\Evidence;

use App\Models\IncidentEvidence;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Removes an evidence item from the incident (Phase 5B): a soft delete with
 * a reason. The stored file is kept: evidence is never physically
 * destroyed through the application.
 */
final class DeleteEvidence
{
    public function __invoke(IncidentEvidence $evidence, string $reason, User $actor): void
    {
        DB::transaction(function () use ($evidence, $reason, $actor): void {
            $evidence->forceFill(['updated_by' => $actor->id])->saveQuietly();
            $evidence->delete();

            AuditLog::event('evidence_deleted', 'Evidence removed from the incident (file kept)', $evidence, ['reason' => $reason], $actor);
        });
    }
}
