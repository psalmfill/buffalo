<?php

namespace App\Actions\Taskings\Attachments;

use App\Actions\Evidence\StoreAttachmentFile;
use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Enums\UploadKind;
use App\Models\Tasking;
use App\Models\TaskingAttachment;
use App\Models\TaskingRecipient;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Stores one attachment on a tasking (its draft) or on an LGA's report
 * back (Phase 11H), through the same code as security report attachments
 * (StoreAttachmentFile): hash first, unaltered original on the evidence
 * disk, malware scan (fail closed), EXIF only from a clean file, integrity
 * verified. The tasking's own attachments carry its state; a report back's
 * carry the recipient LGA's state and LGA.
 *
 * @phpstan-type Detected array{type: EvidenceType, mime: string, extension: string}
 */
final class StoreTaskingAttachment
{
    public function __construct(private readonly StoreAttachmentFile $store) {}

    /**
     * @param  Detected  $detected
     */
    public function __invoke(Tasking $tasking, ?TaskingRecipient $recipient, UploadedFile $file, array $detected, ?string $caption, Classification $classification, User $actor): TaskingAttachment
    {
        $attachment = new TaskingAttachment;
        $attachment->forceFill([
            'tasking_id' => $tasking->id,
            'tasking_recipient_id' => $recipient?->id,
            'state_id' => $tasking->state_id,
            'lga_id' => $recipient?->lga_id,
        ]);

        return ($this->store)($attachment, UploadKind::TaskingAttachment, $file, $detected, $caption, $classification, $actor);
    }
}
