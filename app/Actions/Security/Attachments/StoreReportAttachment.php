<?php

namespace App\Actions\Security\Attachments;

use App\Actions\Evidence\VerifyEvidenceIntegrity;
use App\Enums\Classification;
use App\Enums\EvidenceScanStatus;
use App\Enums\EvidenceType;
use App\Enums\UploadKind;
use App\Jobs\ProcessEvidenceJob;
use App\Models\CoverageReport;
use App\Models\CoverageReportAttachment;
use App\Models\Incident;
use App\Models\User;
use App\Rules\MalwareFree;
use App\Support\Evidence\ExifReader;
use App\Support\Malware\MalwareGuard;
use App\Support\Malware\ScannerUnavailable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Stores one photo or video on a security report (Phase 11G), the way
 * incident evidence is stored (Phase 5B, StoreEvidence):
 *
 * - the SHA-256 of the uploaded bytes is computed first and recorded; the
 *   original is stored unaltered under a generated name;
 * - EXIF (images, once scanned clean) pre-fills the capture time and place;
 * - malware (Phase 8B.4): files up to buffalo.malware.inline_max_mb are
 *   scanned before anything is stored, and refused when the scanner is
 *   unavailable (fail closed: only incident evidence is accepted then).
 *   Larger files are stored `quarantined`, never served or converted, and
 *   scanned on the queue;
 * - small clean files are verified at once, larger ones on the queue.
 *
 * @phpstan-type Detected array{type: EvidenceType, mime: string, extension: string}
 */
final class StoreReportAttachment
{
    public function __construct(
        private readonly ExifReader $exif,
        private readonly VerifyEvidenceIntegrity $verify,
        private readonly MalwareGuard $malware,
    ) {}

    /**
     * @param  Detected  $detected
     */
    public function __invoke(CoverageReport $report, UploadedFile $file, array $detected, ?string $caption, Classification $classification, User $actor): CoverageReportAttachment
    {
        $path = (string) $file->getRealPath();
        $sha256 = hash_file('sha256', $path);

        if ($sha256 === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        $size = (int) filesize($path);
        $scan = $this->scan($path, $size, $actor);
        $queued = $scan === EvidenceScanStatus::Quarantined || $size > (int) config('buffalo.evidence.queue_above_mb') * 1024 * 1024;
        $exif = $detected['type'] === EvidenceType::Image && $scan === EvidenceScanStatus::Clean ? $this->exif->read($path) : null;

        $attachment = DB::transaction(function () use ($report, $file, $path, $detected, $caption, $classification, $actor, $sha256, $size, $exif, $scan): CoverageReportAttachment {
            $attachment = new CoverageReportAttachment;
            $attachment->forceFill([
                'coverage_report_id' => $report->id,
                'state_id' => $report->state_id,
                'lga_id' => $report->lga_id,
                'caption' => $caption,
                'media_type' => $detected['type'],
                'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => $detected['mime'],
                'size_bytes' => $size,
                'sha256' => $sha256,
                'captured_at' => $exif['captured_at'] ?? null,
                'captured_location' => Incident::pointFrom($exif['latitude'] ?? null, $exif['longitude'] ?? null),
                'exif_summary' => $exif['summary'] ?? null,
                'classification' => $classification,
                'scan_status' => $scan,
                'scanned_at' => $scan === EvidenceScanStatus::Clean ? now() : null,
                'uploaded_by' => $actor->id,
                'created_by' => $actor->id,
            ])->save();

            $attachment->addMedia($path)
                ->preservingOriginal()
                ->usingName('original')
                ->usingFileName('original.'.$detected['extension'])
                ->withCustomProperties($scan === EvidenceScanStatus::Clean ? [] : [CoverageReportAttachment::AWAITING_SCAN => true])
                ->toMediaCollection(CoverageReportAttachment::ORIGINAL);

            return $attachment;
        });

        if ($queued) {
            ProcessEvidenceJob::dispatch($attachment->id, $actor->id, CoverageReportAttachment::class);
        } elseif (($this->verify)($attachment, $actor, 'upload')) {
            $attachment->forceFill(['processed_at' => now()])->saveQuietly();
        }

        return $attachment;
    }

    /**
     * @throws ValidationException when the file is infected, or the scanner is unavailable
     */
    private function scan(string $path, int $size, User $actor): EvidenceScanStatus
    {
        if ($size > (int) config('buffalo.malware.inline_max_mb') * 1024 * 1024) {
            return EvidenceScanStatus::Quarantined;
        }

        try {
            $result = $this->malware->inspect($path, UploadKind::ReportAttachment, $actor);
        } catch (ScannerUnavailable) {
            throw ValidationException::withMessages(['file' => MalwareFree::UNAVAILABLE]);
        }

        if ($result->infected) {
            throw ValidationException::withMessages(['file' => MalwareFree::INFECTED]);
        }

        return EvidenceScanStatus::Clean;
    }
}
