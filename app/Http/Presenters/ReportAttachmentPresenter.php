<?php

namespace App\Http\Presenters;

use App\Enums\EvidenceType;
use App\Models\CoverageReport;
use App\Models\CoverageReportAttachment;
use App\Models\User;
use App\Support\Jurisdiction\OfficerNames;
use Illuminate\Support\Collection;

/**
 * Security report attachments for the browser (Phase 11G): ULIDs and
 * labels, never ids or storage paths.
 */
final class ReportAttachmentPresenter
{
    /**
     * The report's attachments the viewer may see, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forReport(CoverageReport $report, User $viewer): array
    {
        $attachments = CoverageReportAttachment::query()->withCoordinates()
            ->where('coverage_report_attachments.coverage_report_id', $report->id)
            ->orderBy('coverage_report_attachments.id')
            ->get();
        $names = OfficerNames::for($attachments->pluck('uploaded_by'));

        return self::rows($attachments, $viewer, $names);
    }

    /**
     * @param  Collection<int, CoverageReportAttachment>  $attachments
     * @param  Collection<int, string>  $names
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $attachments, User $viewer, Collection $names): array
    {
        return array_values($attachments->map(fn (CoverageReportAttachment $a) => self::row($a, $viewer, $names->get($a->uploaded_by)))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(CoverageReportAttachment $attachment, User $viewer, ?string $uploader): array
    {
        $media = $attachment->original();
        $clean = $attachment->isScannedClean();
        $image = $attachment->media_type === EvidenceType::Image;

        return [
            'ulid' => $attachment->ulid,
            'caption' => $attachment->caption,
            'type' => ['value' => $attachment->media_type->value, 'label' => $attachment->media_type->label()],
            'mime_type' => $attachment->mime_type,
            'original_filename' => $attachment->original_filename,
            'size_bytes' => $attachment->size_bytes,
            'sha256' => $attachment->sha256,
            'captured_at' => $attachment->captured_at?->toIso8601String(),
            'captured_at_local' => $attachment->captured_at?->setTimezone((string) config('app.timezone'))->format('Y-m-d\TH:i'),
            'latitude' => $attachment->latitude === null ? null : (float) $attachment->latitude,
            'longitude' => $attachment->longitude === null ? null : (float) $attachment->longitude,
            'classification' => ['value' => $attachment->classificationLevel()->value, 'label' => $attachment->classificationLevel()->label()],
            'processing' => ! $attachment->isProcessed(),
            'scan' => ['value' => $attachment->scan_status->value, 'label' => $attachment->scan_status->label()],
            'has_thumb' => $clean && $image && $media?->hasGeneratedConversion('thumb') === true,
            'has_preview' => $clean && $image && $media?->hasGeneratedConversion('preview') === true,
            'playable' => $clean && $attachment->media_type === EvidenceType::Video,
            'uploaded_by' => $uploader,
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'can' => [
                'update' => $viewer->can('update', $attachment),
                'download' => $clean && $viewer->can('download', $attachment),
                'delete' => $viewer->can('delete', $attachment),
            ],
        ];
    }
}
