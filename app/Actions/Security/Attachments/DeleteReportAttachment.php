<?php

namespace App\Actions\Security\Attachments;

use App\Models\CoverageReportAttachment;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Removes an attachment from a draft or returned security report (Phase
 * 11G): a soft delete with a reason. The stored file is kept.
 */
final class DeleteReportAttachment
{
    public function __invoke(CoverageReportAttachment $attachment, string $reason, User $actor): void
    {
        DB::transaction(function () use ($attachment, $reason, $actor): void {
            $attachment->forceFill(['updated_by' => $actor->id])->saveQuietly();
            $attachment->delete();

            AuditLog::event('attachment_deleted', 'Attachment removed from the security report (file kept)', $attachment, ['reason' => $reason], $actor);
        });
    }
}
