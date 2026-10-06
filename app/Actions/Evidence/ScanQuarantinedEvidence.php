<?php

namespace App\Actions\Evidence;

use App\Enums\EvidenceScanStatus;
use App\Enums\EvidenceType;
use App\Enums\UploadKind;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\User;
use App\Support\Evidence\ExifReader;
use App\Support\Malware\MalwareGuard;
use App\Support\Malware\MalwareScanner;
use App\Support\Malware\ScannerUnavailable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\FileManipulator;

/**
 * Scans a quarantined evidence file (Phase 8B.4): large uploads, and uploads
 * accepted while the scanner was unavailable.
 *
 * - Clean: the item leaves quarantine; EXIF is read now (never from an
 *   unscanned file) and image previews are generated.
 * - Infected: the original is moved to the `quarantine` disk under a
 *   neutral name and removed from the evidence disk; the item is marked
 *   `infected` and is never served. A `malware_detected` event is logged and
 *   super admins and the uploader's State Director are notified.
 * - Scanner unavailable: nothing changes; returns null so the caller can
 *   retry later.
 */
final class ScanQuarantinedEvidence
{
    public function __construct(
        private readonly MalwareScanner $scanner,
        private readonly MalwareGuard $guard,
        private readonly ExifReader $exif,
        private readonly FileManipulator $conversions,
    ) {}

    public function __invoke(IncidentEvidence $evidence, User $uploader): ?EvidenceScanStatus
    {
        if ($evidence->scan_status !== EvidenceScanStatus::Quarantined) {
            return $evidence->scan_status;
        }

        $media = $evidence->original();
        $path = $media?->getPath();

        if ($media === null || $path === null || ! is_file($path)) {
            // Missing file: integrity verification reports it.
            return EvidenceScanStatus::Quarantined;
        }

        try {
            $result = $this->scanner->scan($path);
        } catch (ScannerUnavailable) {
            return null;
        }

        if ($result->infected) {
            $this->quarantine($evidence, $path);
            $media->delete();
            $evidence->forceFill(['scan_status' => EvidenceScanStatus::Infected, 'scanned_at' => now()])->saveQuietly();
            $this->guard->report(UploadKind::Evidence, $evidence->ulid, (string) $result->signature, $uploader, $evidence);

            return EvidenceScanStatus::Infected;
        }

        $values = ['scan_status' => EvidenceScanStatus::Clean, 'scanned_at' => now()];

        if ($evidence->evidence_type === EvidenceType::Image && $evidence->exif_summary === null) {
            $exif = $this->exif->read($path);
            $values += [
                'exif_summary' => $exif['summary'],
                'captured_at' => $evidence->captured_at ?? $exif['captured_at'],
                'captured_location' => Incident::pointFrom($exif['latitude'], $exif['longitude']),
            ];
        }

        $evidence->forceFill($values)->saveQuietly();
        $media->forgetCustomProperty(IncidentEvidence::AWAITING_SCAN);
        $media->save();
        $this->conversions->createDerivedFiles($media);

        return EvidenceScanStatus::Clean;
    }

    public static function quarantinePath(IncidentEvidence $evidence): string
    {
        return "evidence/{$evidence->ulid}/original.quarantined";
    }

    private function quarantine(IncidentEvidence $evidence, string $path): void
    {
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException('The quarantined file could not be read.');
        }

        try {
            Storage::disk('quarantine')->writeStream(self::quarantinePath($evidence), $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
