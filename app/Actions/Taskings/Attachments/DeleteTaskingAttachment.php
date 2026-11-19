<?php

namespace App\Actions\Taskings\Attachments;

use App\Models\TaskingAttachment;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Removes an attachment from a tasking draft, or from an LGA's report back
 * before it is completed (Phase 11H): a soft delete with a reason. The
 * stored file is kept.
 */
final class DeleteTaskingAttachment
{
    public function __invoke(TaskingAttachment $attachment, string $reason, User $actor): void
    {
        DB::transaction(function () use ($attachment, $reason, $actor): void {
            $attachment->forceFill(['updated_by' => $actor->id])->saveQuietly();
            $attachment->delete();

            AuditLog::event('attachment_deleted', 'Attachment removed from the tasking (file kept)', $attachment, ['reason' => $reason], $actor);
        });
    }
}
