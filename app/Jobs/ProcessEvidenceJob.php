<?php

namespace App\Jobs;

use App\Actions\Evidence\ScanQuarantinedEvidence;
use App\Actions\Evidence\VerifyEvidenceIntegrity;
use App\Enums\EvidenceScanStatus;
use App\Models\IncidentEvidence;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scans and verifies an evidence upload after it is stored (Phases 5B and
 * 8B.4).
 *
 * 1. A quarantined file is scanned for malware first (ScanQuarantinedEvidence).
 *    While the scanner is unavailable it stays quarantined;
 *    `buffalo:malware:scan-quarantined` dispatches this job again later.
 * 2. A clean file's stored copy must match the SHA-256 recorded from the
 *    uploaded bytes. The item leaves "processing" when it does; a mismatch
 *    is an integrity failure (logged, DG and super admins notified) and the
 *    item stays unprocessed.
 *
 * Runs as the uploader (CLAUDE.md: a job acting for a user uses that user's
 * context). Unique per item, so retries never pile up.
 */
final class ProcessEvidenceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $evidenceId,
        public readonly int $userId,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->evidenceId;
    }

    public function handle(JurisdictionContext $context, ScanQuarantinedEvidence $scan, VerifyEvidenceIntegrity $verify): void
    {
        $user = User::query()->findOrFail($this->userId);

        $context->actingAs($user, function () use ($user, $scan, $verify): void {
            $evidence = IncidentEvidence::query()->whereKey($this->evidenceId)->first();

            if ($evidence === null || $scan($evidence, $user) !== EvidenceScanStatus::Clean || $evidence->isProcessed()) {
                return;
            }

            if ($verify($evidence, $user, 'upload')) {
                $evidence->forceFill(['processed_at' => now()])->saveQuietly();
            }
        });
    }
}
