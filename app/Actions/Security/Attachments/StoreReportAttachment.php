<?php

namespace App\Actions\Security\Attachments;

use App\Actions\Evidence\StoreAttachmentFile;
use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Enums\UploadKind;
use App\Models\CoverageReport;
use App\Models\CoverageReportAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Stores one photo or video on a security report (Phase 11G), the way
 * incident evidence is stored (StoreAttachmentFile, shared with tasking
 * attachments since Phase 11H): hash first, unaltered original, malware
 * scan (fail closed), EXIF only from a clean file, integrity verified.
 *
 * @phpstan-type Detected array{type: EvidenceType, mime: string, extension: string}
 */
final class StoreReportAttachment
{
    public function __construct(private readonly StoreAttachmentFile $store) {}

    /**
     * @param  Detected  $detected
     */
    public function __invoke(CoverageReport $report, UploadedFile $file, array $detected, ?string $caption, Classification $classification, User $actor): CoverageReportAttachment
    {
        $attachment = new CoverageReportAttachment;
        $attachment->forceFill([
            'coverage_report_id' => $report->id,
            'state_id' => $report->state_id,
            'lga_id' => $report->lga_id,
        ]);

        return ($this->store)($attachment, UploadKind::ReportAttachment, $file, $detected, $caption, $classification, $actor);
    }
}
