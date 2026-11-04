<?php

namespace App\Http\Controllers\Security;

use App\Actions\Evidence\VerifyEvidenceIntegrity;
use App\Actions\Security\Attachments\DeleteReportAttachment;
use App\Actions\Security\Attachments\StoreReportAttachment;
use App\Actions\Security\Attachments\UpdateReportAttachment;
use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Evidence\Concerns\ServesStoredFiles;
use App\Http\Presenters\ReportAttachmentPresenter;
use App\Http\Requests\Security\Attachments\ReportAttachmentDeleteRequest;
use App\Http\Requests\Security\Attachments\ReportAttachmentUpdateRequest;
use App\Http\Requests\Security\Attachments\ReportAttachmentUploadRequest;
use App\Models\CoverageReport;
use App\Models\CoverageReportAttachment;
use App\Models\User;
use App\Support\Auth\StepUp;
use App\Support\Exports\ExportControls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Photos and videos of a security report (Phase 11G): upload (one file per
 * request, JSON, so the page shows progress), caption and metadata edits,
 * removal with a reason, and the files themselves, served exactly as
 * incident evidence is (ServesStoredFiles). {report} and {attachment} are
 * bound through their scopes (jurisdiction and classification): anything
 * out of reach is 404. Every view and download is access-logged.
 */
class ReportAttachmentController extends Controller
{
    use ServesStoredFiles;

    private const PREFIX = 'report-attachment';

    public function store(ReportAttachmentUploadRequest $request, CoverageReport $report, StoreReportAttachment $store): JsonResponse
    {
        $user = $this->actor($request);
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $attachment = $store($report, $file, $request->detected(), $request->caption(), $request->classification(), $user);
        $row = CoverageReportAttachment::query()->withCoordinates()->whereKey($attachment->id)->firstOrFail();

        return response()->json([
            'attachment' => ReportAttachmentPresenter::row($row, $user, $user->name),
            'notice' => $row->isScannedClean() ? null : 'The file is being checked for malware. It can be viewed once the check has passed.',
        ], 201);
    }

    public function update(ReportAttachmentUpdateRequest $request, CoverageReportAttachment $attachment, UpdateReportAttachment $update): RedirectResponse
    {
        $update($attachment, $request->values(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Attachment details saved.']);
    }

    public function destroy(ReportAttachmentDeleteRequest $request, CoverageReportAttachment $attachment, DeleteReportAttachment $delete): RedirectResponse
    {
        $delete($attachment, (string) $request->validated('reason'), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Attachment removed. The file is kept.']);
    }

    public function thumb(CoverageReportAttachment $attachment): BinaryFileResponse
    {
        $this->authorize('view', $attachment);

        // Not logged individually: the gallery on the report page is.
        return $this->conversionResponse($attachment, 'thumb', self::PREFIX);
    }

    public function preview(CoverageReportAttachment $attachment): BinaryFileResponse
    {
        $this->authorize('view', $attachment);
        $response = $this->conversionResponse($attachment, 'preview', self::PREFIX);
        $attachment->recordAccess(AccessAction::View, 'preview');

        return $response;
    }

    public function stream(Request $request, CoverageReportAttachment $attachment): BinaryFileResponse
    {
        $this->authorize('view', $attachment);
        abort_unless($attachment->media_type === EvidenceType::Video, 404);
        $response = $this->streamResponse($attachment, self::PREFIX);

        // Players fetch many ranges: one log entry per item per session.
        $key = 'attachment_streamed.'.$attachment->ulid;

        if (! $request->session()->has($key)) {
            $attachment->recordAccess(AccessAction::View, 'stream');
            $request->session()->put($key, true);
        }

        return $response;
    }

    public function download(Request $request, CoverageReportAttachment $attachment, VerifyEvidenceIntegrity $verify): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('download', $attachment);
        // Originals above restricted need a recent TOTP code (Phase 8B.2).
        if ($attachment->classificationLevel() !== Classification::Restricted) {
            StepUp::ensure($request);
        }
        $user = $this->actor($request);
        $media = $this->servableMedia($attachment);
        // The daily quota on evidence originals applies (Phase 8B.3).
        app(ExportControls::class)->assertCanDownloadEvidence($user);

        if (! $verify($attachment, $user, 'download')) {
            return redirect()->route('coverage-reports.show', $attachment->report)->with('toast', [
                'type' => 'error',
                'message' => 'This file failed its integrity check and cannot be downloaded. The Director General has been notified.',
            ]);
        }

        $attachment->recordAccess(AccessAction::Download);
        app(ExportControls::class)->afterExport($user);

        return $this->downloadResponse($attachment, $media, self::PREFIX);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
