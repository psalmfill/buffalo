<?php

namespace App\Http\Controllers\Taskings;

use App\Actions\Evidence\VerifyEvidenceIntegrity;
use App\Actions\Taskings\Attachments\DeleteTaskingAttachment;
use App\Actions\Taskings\Attachments\StoreTaskingAttachment;
use App\Actions\Taskings\Attachments\UpdateTaskingAttachment;
use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Evidence\Concerns\ServesStoredFiles;
use App\Http\Presenters\TaskingPresenter;
use App\Http\Requests\Taskings\Attachments\TaskingAttachmentDeleteRequest;
use App\Http\Requests\Taskings\Attachments\TaskingAttachmentUpdateRequest;
use App\Http\Requests\Taskings\Attachments\TaskingAttachmentUploadRequest;
use App\Models\Tasking;
use App\Models\TaskingAttachment;
use App\Models\TaskingRecipient;
use App\Models\User;
use App\Support\Auth\StepUp;
use App\Support\Exports\ExportControls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Attachments of a tasking (its draft) and of an LGA's report back
 * (Phase 11H), served exactly as security report attachments and incident
 * evidence are (ServesStoredFiles). {tasking}, {recipient} and
 * {attachment} are bound through their scopes: anything out of reach is
 * 404. Every view and download is access-logged.
 */
class TaskingAttachmentController extends Controller
{
    use ServesStoredFiles;

    private const PREFIX = 'tasking-attachment';

    public function store(TaskingAttachmentUploadRequest $request, Tasking $tasking, StoreTaskingAttachment $store): JsonResponse
    {
        return $this->upload($request, $tasking, null, $store);
    }

    public function storeForResponse(TaskingAttachmentUploadRequest $request, TaskingRecipient $recipient, StoreTaskingAttachment $store): JsonResponse
    {
        return $this->upload($request, $request->tasking(), $recipient, $store);
    }

    public function update(TaskingAttachmentUpdateRequest $request, TaskingAttachment $attachment, UpdateTaskingAttachment $update): RedirectResponse
    {
        $update($attachment, $request->values(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Attachment details saved.']);
    }

    public function destroy(TaskingAttachmentDeleteRequest $request, TaskingAttachment $attachment, DeleteTaskingAttachment $delete): RedirectResponse
    {
        $delete($attachment, (string) $request->validated('reason'), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Attachment removed. The file is kept.']);
    }

    public function thumb(TaskingAttachment $attachment): BinaryFileResponse
    {
        $this->authorize('view', $attachment);

        // Not logged individually: the gallery on the tasking page is.
        return $this->conversionResponse($attachment, 'thumb', self::PREFIX);
    }

    public function preview(TaskingAttachment $attachment): BinaryFileResponse
    {
        $this->authorize('view', $attachment);
        $response = $this->conversionResponse($attachment, 'preview', self::PREFIX);
        $attachment->recordAccess(AccessAction::View, 'preview');

        return $response;
    }

    public function stream(Request $request, TaskingAttachment $attachment): BinaryFileResponse
    {
        $this->authorize('view', $attachment);
        abort_unless($attachment->media_type === EvidenceType::Video, 404);
        $response = $this->streamResponse($attachment, self::PREFIX);

        // Players fetch many ranges: one log entry per item per session.
        $key = 'tasking_attachment_streamed.'.$attachment->ulid;

        if (! $request->session()->has($key)) {
            $attachment->recordAccess(AccessAction::View, 'stream');
            $request->session()->put($key, true);
        }

        return $response;
    }

    public function download(Request $request, TaskingAttachment $attachment, VerifyEvidenceIntegrity $verify): BinaryFileResponse|RedirectResponse
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
            return redirect()->route('taskings.show', $attachment->tasking)->with('toast', [
                'type' => 'error',
                'message' => 'This file failed its integrity check and cannot be downloaded. The Director General has been notified.',
            ]);
        }

        $attachment->recordAccess(AccessAction::Download);
        app(ExportControls::class)->afterExport($user);

        return $this->downloadResponse($attachment, $media, self::PREFIX);
    }

    private function upload(TaskingAttachmentUploadRequest $request, Tasking $tasking, ?TaskingRecipient $recipient, StoreTaskingAttachment $store): JsonResponse
    {
        $user = $this->actor($request);
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $attachment = $store($tasking, $recipient, $file, $request->detected(), $request->caption(), $request->classification(), $user);
        $row = TaskingAttachment::query()->withCoordinates()->whereKey($attachment->id)->firstOrFail();

        return response()->json([
            'attachment' => TaskingPresenter::attachment($row, $user, $user->name),
            'notice' => $row->isScannedClean() ? null : 'The file is being checked for malware. It can be viewed once the check has passed.',
        ], 201);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
