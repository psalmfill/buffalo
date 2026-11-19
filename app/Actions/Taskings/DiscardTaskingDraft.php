<?php

namespace App\Actions\Taskings;

use App\Models\Tasking;
use App\Models\User;
use InvalidArgumentException;

/** Discards (soft-deletes) a tasking draft (Phase 11H). Sent taskings are kept for ever. */
final class DiscardTaskingDraft
{
    public function __invoke(Tasking $tasking, User $actor): void
    {
        if (! $tasking->isDraft()) {
            throw new InvalidArgumentException('Only drafts can be discarded.');
        }

        $tasking->forceFill(['updated_by' => $actor->id])->saveQuietly();
        $tasking->delete();
    }
}
