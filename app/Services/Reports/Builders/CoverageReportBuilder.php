<?php

namespace App\Services\Reports\Builders;

use App\Enums\EvidenceScanStatus;
use App\Enums\EvidenceType;
use App\Http\Presenters\CoverageReportPresenter;
use App\Models\CoverageReport;
use App\Models\CoverageReportAttachment;
use App\Models\CoverageReportDeployment;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use App\Support\Security\CoverageAnalysis;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

/**
 * One security report as a PDF (Phase 7D; renamed and extended in 11G): the
 * written report, thumbnails of up to 12 photos with captions and a list of
 * videos (caption and capture date), the optional details, its deployments
 * (formations the requester cannot see are not named) with totals and
 * uncovered communities (names only, no coordinates) when deployments were
 * entered, and the workflow history. The level is the report's own
 * classification, raised by any formation it names and any attachment it
 * shows. Photos are the EXIF-free thumbnails, never the originals.
 */
final class CoverageReportBuilder implements ReportBuilder
{
    /** Photo thumbnails in the PDF. */
    private const PHOTOS = 12;

    public function __construct(private readonly PdfRenderer $pdf, private readonly CoverageAnalysis $analysis) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $report = CoverageReport::query()->where('ulid', (string) $run->parameters['report'])->first();

        if ($report === null || ! $user->can('view', $report)) {
            throw new AuthorizationException('The security report is no longer visible to the requester.');
        }

        $report->recordAccess(context: 'report');
        $report->load(['lga', 'state']);
        $deployments = CoverageReportDeployment::query()->with(['agency', 'formation', 'communities'])
            ->where('coverage_report_id', $report->id)->orderBy('id')->get();
        $levels = new LevelTracker;
        $levels->see($report->classificationLevel());
        $levels->seeAll($deployments->map(fn (CoverageReportDeployment $d) => $d->formation?->classificationLevel()));

        [$photos, $videos, $files] = $this->attachments($report, $levels);
        $hasDeployments = $deployments->isNotEmpty();

        return $this->pdf->render('reports.coverage', [
            'report' => CoverageReportPresenter::detail($report),
            'photos' => $photos,
            'videos' => $videos,
            'deployments' => $deployments->map(fn (CoverageReportDeployment $d) => CoverageReportPresenter::deployment($d))->all(),
            'totals' => $hasDeployments ? $this->analysis->totals($report) : null,
            'uncovered' => $hasDeployments ? $this->analysis->uncovered($report)->pluck('name')->all() : null,
            'timeline' => CoverageReportPresenter::timeline($report),
        ], $report->displayTitle(), $run, $user, $levels, $files);
    }

    /**
     * Clean photos (up to PHOTOS, as files sent with the request) and
     * videos the requester can see. Every attachment shown is access-logged.
     *
     * @return array{0: list<array{file: string, caption: string|null, captured: string|null}>, 1: list<array{caption: string|null, captured: string|null}>, 2: array<string, string>}
     */
    private function attachments(CoverageReport $report, LevelTracker $levels): array
    {
        $photos = [];
        $videos = [];
        $files = [];

        $attachments = CoverageReportAttachment::query()->where('coverage_report_id', $report->id)
            ->where('scan_status', EvidenceScanStatus::Clean->value)->orderBy('id')->get();

        foreach ($attachments as $attachment) {
            $captured = $attachment->captured_at?->setTimezone((string) config('app.timezone'))->format('j M Y H:i');

            if ($attachment->media_type === EvidenceType::Video) {
                $videos[] = ['caption' => $attachment->caption, 'captured' => $captured];
                $levels->see($attachment->classificationLevel());
                $attachment->recordAccess(context: 'report');

                continue;
            }

            $media = $attachment->original();

            if (count($photos) >= self::PHOTOS || $media === null || ! $media->hasGeneratedConversion('thumb')) {
                continue;
            }

            $contents = Storage::disk($media->disk)->get($media->getPathRelativeToRoot('thumb'));

            if (! is_string($contents)) {
                continue;
            }

            $name = 'attachment-'.(count($photos) + 1).'.jpg';
            $files[$name] = $contents;
            $photos[] = ['file' => $name, 'caption' => $attachment->caption, 'captured' => $captured];
            $levels->see($attachment->classificationLevel());
            $attachment->recordAccess(context: 'report');
        }

        return [$photos, $videos, $files];
    }
}
