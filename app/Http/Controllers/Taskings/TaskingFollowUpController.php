<?php

namespace App\Http\Controllers\Taskings;

use App\Actions\Taskings\AddTaskingNote;
use App\Actions\Taskings\RecordTaskingProgress;
use App\Http\Controllers\Controller;
use App\Http\Requests\Taskings\TaskingTextRequest;
use App\Models\Tasking;
use App\Models\TaskingRecipient;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * What happens after sending (Phase 11H): the directors' follow-up notes,
 * and the recipient LGA's acknowledgement, completion report and further
 * responses. {recipient} is bound through its own scope (an LGA chief
 * reaches only their own LGA's row; anything else is 404).
 */
class TaskingFollowUpController extends Controller
{
    public function note(TaskingTextRequest $request, Tasking $tasking, AddTaskingNote $add): RedirectResponse
    {
        $this->authorize('addNote', $tasking);
        $add($tasking, $request->text(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Follow-up note added. Recipients have been told.']);
    }

    public function acknowledge(Request $request, TaskingRecipient $recipient, RecordTaskingProgress $progress): RedirectResponse
    {
        $this->authorize('respond', $recipient);
        $progress->acknowledge($recipient, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Tasking acknowledged. The Director has been told.']);
    }

    public function complete(TaskingTextRequest $request, TaskingRecipient $recipient, RecordTaskingProgress $progress): RedirectResponse
    {
        $this->authorize('respond', $recipient);
        $progress->complete($recipient, $request->text(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Tasking marked completed. The Director has your report.']);
    }

    public function respond(TaskingTextRequest $request, TaskingRecipient $recipient, RecordTaskingProgress $progress): RedirectResponse
    {
        $this->authorize('respond', $recipient);
        $progress->respond($recipient, $request->text(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Response added. The Director has been told.']);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
