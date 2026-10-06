<?php

namespace App\Actions\Evidence;

use App\Enums\EvidenceScanStatus;
use App\Enums\Role;
use App\Models\IncidentEvidence;
use App\Models\User;
use App\Notifications\EvidenceIntegrityFailure;
use App\Support\Audit\AuditLog;
use App\Support\Notifications\NotifyRole;
use Illuminate\Support\Facades\Storage;

/**
 * Recomputes the SHA-256 of a stored original and compares it with the hash
 * recorded at upload (Phase 5B). Used before every download, by "Verify
 * integrity", and after a queued upload is stored.
 *
 * A mismatch (or a missing file) is logged as `integrity_failure` and the DG
 * and super admins are notified (critical, evidence ULID only). A requested
 * verification that passes is logged as `integrity_verified`.
 */
final class VerifyEvidenceIntegrity
{
    public function __construct(private readonly NotifyRole $notify) {}

    /**
     * @param  string  $context  download, verify or upload
     */
    public function __invoke(IncidentEvidence $evidence, ?User $actor, string $context): bool
    {
        $actual = $this->hash($evidence);

        if ($actual === $evidence->sha256) {
            if ($context === 'verify') {
                AuditLog::event('integrity_verified', 'Integrity verified: the stored file matches its recorded hash', $evidence, [
                    'sha256' => $actual,
                ], $actor);
            }

            return true;
        }

        AuditLog::event('integrity_failure', 'Integrity check failed: the stored file does not match its recorded hash', $evidence, [
            'context' => $context,
            'expected_sha256' => $evidence->sha256,
            'actual_sha256' => $actual,
        ], $actor);

        $notification = new EvidenceIntegrityFailure($evidence->ulid);
        $this->notify->send($notification, Role::DirectorGeneral);
        $this->notify->send($notification, Role::SuperAdmin);

        return false;
    }

    /**
     * The stored original's SHA-256, or null when the file is missing. An
     * infected original is checked where it now lives, on the quarantine
     * disk (Phase 8B.4).
     */
    private function hash(IncidentEvidence $evidence): ?string
    {
        $path = $evidence->scan_status === EvidenceScanStatus::Infected
            ? Storage::disk('quarantine')->path(ScanQuarantinedEvidence::quarantinePath($evidence))
            : $evidence->original()?->getPath();

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }
}
