<?php

namespace App\Actions\Taskings;

use App\Enums\NotificationSeverity;
use App\Enums\TaskingStatus;
use App\Models\Tasking;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Taskings\TaskingNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recalls a sent tasking with a reason (Phase 11H). Recipients still see
 * it, marked "Recalled by [name]: [reason]", and can no longer acknowledge
 * or complete it. The content stays exactly as sent; a correction is a new
 * tasking. Each recipient LGA's chief is told (warning).
 */
final class RecallTasking
{
    public function __construct(private readonly TaskingNotifier $notifier) {}

    public function __invoke(Tasking $tasking, string $reason, User $actor): void
    {
        DB::transaction(function () use ($tasking, $reason, $actor): void {
            /** @var Tasking $locked */
            $locked = Tasking::query()->whereKey($tasking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TaskingStatus::Sent) {
                throw ValidationException::withMessages(['reason' => 'Only a sent tasking can be recalled.']);
            }

            $locked->forceFill([
                'status' => TaskingStatus::Recalled,
                'recalled_by' => $actor->id,
                'recalled_at' => now(),
                'recall_reason' => $reason,
                'updated_by' => $actor->id,
            ])->save();

            AuditLog::event('tasking_recalled', 'Tasking recalled', $locked, ['reference' => $locked->reference_no], $actor);
            $tasking->setRawAttributes($locked->getAttributes(), true);
        });

        $this->notifier->toChiefs('recalled', $tasking, NotificationSeverity::Warning);
    }
}
