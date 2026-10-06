<?php

namespace App\Http\Controllers\Evidence;

use App\Actions\Evidence\DeleteEvidence;
use App\Actions\Evidence\StoreEvidence;
use App\Actions\Evidence\UpdateEvidence;
use App\Actions\Evidence\VerifyEvidenceIntegrity;
use App\Http\Controllers\Controller;
use App\Http\Presenters\EvidencePresenter;
use App\Http\Requests\Evidence\EvidenceDeleteRequest;
use App\Http\Requests\Evidence\EvidenceUpdateRequest;
use App\Http\Requests\Evidence\EvidenceUploadRequest;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Incident evidence (Phase 5B): upload (one file per request, JSON, so the
 * page can show progress), metadata edits, removal, integrity checks and
 * the chain of custody. {incident} and {evidence} are bound through their
 * scopes (jurisdiction and classification): anything else is 404.
 */
class IncidentEvidenceController extends Controller
{
    public function store(EvidenceUploadRequest $request, Incident $incident, StoreEvidence $store): JsonResponse
    {
        $user = $this->actor($request);
        $evidence = $store($incident, $request->file('file'), $request->detected(), $request->values(), $user);
        $row = IncidentEvidence::query()->withCoordinates()->whereKey($evidence->id)->firstOrFail();

        return response()->json([
            'evidence' => EvidencePresenter::row($row, $user, $user->name),
            'notice' => $row->isScannedClean() ? null : self::quarantineNotice($row),
        ], 201);
    }

    /** A link to an item (e.g. from a notification) opens its incident. */
    public function show(Request $request, IncidentEvidence $evidence): RedirectResponse
    {
        $this->authorize('view', $evidence);

        return redirect()->to(route('incidents.show', $evidence->incident).'#evidence-'.$evidence->ulid);
    }

    public function update(EvidenceUpdateRequest $request, IncidentEvidence $evidence, UpdateEvidence $update): RedirectResponse
    {
        $update($evidence, $request->values(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Evidence details saved.']);
    }

    public function destroy(EvidenceDeleteRequest $request, IncidentEvidence $evidence, DeleteEvidence $delete): RedirectResponse
    {
        $delete($evidence, (string) $request->validated('reason'), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Evidence removed. The file is kept.']);
    }

    public function verify(Request $request, IncidentEvidence $evidence, VerifyEvidenceIntegrity $verify): RedirectResponse
    {
        $this->authorize('verify', $evidence);

        return $verify($evidence, $this->actor($request), 'verify')
            ? back()->with('toast', ['type' => 'success', 'message' => 'Integrity verified: the stored file matches its recorded hash.'])
            : back()->with('toast', ['type' => 'error', 'message' => 'Integrity check failed: the stored file does not match its recorded hash. The Director General has been notified.']);
    }

    public function custody(Request $request, IncidentEvidence $evidence): JsonResponse
    {
        $this->authorize('view', $evidence);

        return response()->json(['entries' => EvidencePresenter::custody($evidence)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /** Why a new upload is held in quarantine (Phase 8B.4). */
    private static function quarantineNotice(IncidentEvidence $evidence): string
    {
        return $evidence->size_bytes > (int) config('buffalo.malware.inline_max_mb') * 1024 * 1024
            ? 'Uploaded. Large files are held in quarantine until their malware scan finishes; this takes a few minutes.'
            : 'Uploaded, but malware scanning is unavailable: the file is held in quarantine and will be scanned when the scanner returns.';
    }
}
