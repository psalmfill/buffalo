<?php

namespace App\Http\Controllers\Evidence;

use App\Actions\Evidence\VerifyEvidenceIntegrity;
use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Http\Controllers\Controller;
use App\Models\IncidentEvidence;
use App\Models\Media;
use App\Models\User;
use App\Support\Auth\StepUp;
use App\Support\Exports\ExportControls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Serves evidence files (Phase 5B), never by URL from storage: each route
 * authorises through the evidence policy ({evidence} is bound through its
 * scopes, so anything out of reach is 404) and sends the file with no
 * caching.
 *
 * - thumb / preview: image conversions (EXIF-stripped). Until they exist
 *   the route is 404: the original, which keeps its EXIF, is never shown
 *   in their place.
 * - stream: video and audio originals, inline, with HTTP range support.
 * - download: the original, unaltered, as an attachment, after its SHA-256
 *   is checked. Documents are only ever served this way.
 */
class EvidenceFileController extends Controller
{
    private const SECURITY_HEADERS = [
        'Cache-Control' => 'private, no-store',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; sandbox",
    ];

    public function thumb(IncidentEvidence $evidence): BinaryFileResponse
    {
        $this->authorize('view', $evidence);

        // Not logged individually: listing the item on the incident page is.
        return $this->conversion($evidence, 'thumb');
    }

    public function preview(IncidentEvidence $evidence): BinaryFileResponse
    {
        $this->authorize('view', $evidence);
        $response = $this->conversion($evidence, 'preview');
        $evidence->recordAccess(AccessAction::View, 'preview');

        return $response;
    }

    public function stream(Request $request, IncidentEvidence $evidence): BinaryFileResponse
    {
        $this->authorize('view', $evidence);
        abort_unless(in_array($evidence->evidence_type, [EvidenceType::Video, EvidenceType::Audio], true), 404);

        $media = $this->media($evidence);
        abort_unless(is_file($media->getPath()), 404);

        // Players fetch many ranges: one log entry per item per session.
        $key = 'evidence_streamed.'.$evidence->ulid;

        if (! $request->session()->has($key)) {
            $evidence->recordAccess(AccessAction::View, 'stream');
            $request->session()->put($key, true);
        }

        $response = new BinaryFileResponse($media->getPath(), 200, [
            ...self::SECURITY_HEADERS,
            'Content-Type' => $evidence->mime_type,
        ], false, ResponseHeaderBag::DISPOSITION_INLINE);

        return $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $this->filename($evidence, $media));
    }

    public function download(Request $request, IncidentEvidence $evidence, VerifyEvidenceIntegrity $verify): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('download', $evidence);
        // Originals above restricted need a recent TOTP code (Phase 8B.2).
        if ($evidence->classificationLevel() !== Classification::Restricted) {
            StepUp::ensure($request);
        }
        /** @var User $user */
        $user = $request->user();
        // Daily quota on evidence originals (Phase 8B.3).
        app(ExportControls::class)->assertCanDownloadEvidence($user);
        $media = $this->media($evidence);

        if (! $verify($evidence, $user, 'download')) {
            return redirect()->route('incidents.show', $evidence->incident)->with('toast', [
                'type' => 'error',
                'message' => 'This file failed its integrity check and cannot be downloaded. The Director General has been notified.',
            ]);
        }

        $evidence->recordAccess(AccessAction::Download);
        app(ExportControls::class)->afterExport($user);

        $response = new BinaryFileResponse($media->getPath(), 200, [
            ...self::SECURITY_HEADERS,
            'Content-Type' => 'application/octet-stream',
        ], false);

        return $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->filename($evidence, $media));
    }

    private function conversion(IncidentEvidence $evidence, string $name): BinaryFileResponse
    {
        abort_unless($evidence->evidence_type === EvidenceType::Image, 404);
        $media = $this->media($evidence);
        abort_unless($media->hasGeneratedConversion($name), 404);

        $response = new BinaryFileResponse($media->getPath($name), 200, [
            ...self::SECURITY_HEADERS,
            'Content-Type' => 'image/jpeg',
        ], false);

        return $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, "evidence-{$name}.jpg");
    }

    private function media(IncidentEvidence $evidence): Media
    {
        $media = $evidence->original();
        abort_if($media === null, 404);

        return $media;
    }

    /** A generated name: never the uploader's file name. */
    private function filename(IncidentEvidence $evidence, Media $media): string
    {
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION)));

        return 'evidence-'.strtolower($evidence->ulid).($extension === '' ? '' : '.'.$extension);
    }
}
