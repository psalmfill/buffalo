<?php

namespace App\Http\Controllers\Evidence;

use App\Actions\Evidence\VerifyEvidenceIntegrity;
use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Evidence\Concerns\ServesStoredFiles;
use App\Models\IncidentEvidence;
use App\Models\User;
use App\Support\Auth\StepUp;
use App\Support\Exports\ExportControls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
    use ServesStoredFiles;

    public function thumb(IncidentEvidence $evidence): BinaryFileResponse
    {
        $this->authorize('view', $evidence);

        // Not logged individually: listing the item on the incident page is.
        return $this->conversionResponse($evidence, 'thumb', 'evidence');
    }

    public function preview(IncidentEvidence $evidence): BinaryFileResponse
    {
        $this->authorize('view', $evidence);
        $response = $this->conversionResponse($evidence, 'preview', 'evidence');
        $evidence->recordAccess(AccessAction::View, 'preview');

        return $response;
    }

    public function stream(Request $request, IncidentEvidence $evidence): BinaryFileResponse
    {
        $this->authorize('view', $evidence);
        abort_unless(in_array($evidence->evidence_type, [EvidenceType::Video, EvidenceType::Audio], true), 404);
        $response = $this->streamResponse($evidence, 'evidence');

        // Players fetch many ranges: one log entry per item per session.
        $key = 'evidence_streamed.'.$evidence->ulid;

        if (! $request->session()->has($key)) {
            $evidence->recordAccess(AccessAction::View, 'stream');
            $request->session()->put($key, true);
        }

        return $response;
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
        $media = $this->servableMedia($evidence);
        app(ExportControls::class)->assertCanDownloadEvidence($user);

        if (! $verify($evidence, $user, 'download')) {
            return redirect()->route('incidents.show', $evidence->incident)->with('toast', [
                'type' => 'error',
                'message' => 'This file failed its integrity check and cannot be downloaded. The Director General has been notified.',
            ]);
        }

        $evidence->recordAccess(AccessAction::Download);
        app(ExportControls::class)->afterExport($user);

        return $this->downloadResponse($evidence, $media, 'evidence');
    }
}
