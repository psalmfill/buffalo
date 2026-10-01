<?php

namespace App\Jobs;

use App\Actions\Evidence\VerifyEvidenceIntegrity;
use App\Models\IncidentEvidence;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Verifies a large evidence upload after it is stored (Phase 5B): the stored
 * copy's SHA-256 must equal the hash recorded from the uploaded bytes. The
 * item leaves "processing" when it does; a mismatch is an integrity
 * failure (logged, DG and super admins notified) and the item stays
 * unprocessed. Runs as the uploader (CLAUDE.md: a job acting for a user
 * uses that user's context).
 */
final class ProcessEvidenceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(
        public readonly int $evidenceId,
        public readonly int $userId,
    ) {
        $this->afterCommit();
    }

    public function handle(JurisdictionContext $context, VerifyEvidenceIntegrity $verify): void
    {
        $user = User::query()->findOrFail($this->userId);

        $context->actingAs($user, function () use ($user, $verify): void {
            $evidence = IncidentEvidence::query()->whereKey($this->evidenceId)->first();

            if ($evidence === null || $evidence->isProcessed()) {
                return;
            }

            if ($verify($evidence, $user, 'upload')) {
                $evidence->forceFill(['processed_at' => now()])->saveQuietly();
            }
        });
    }
}
