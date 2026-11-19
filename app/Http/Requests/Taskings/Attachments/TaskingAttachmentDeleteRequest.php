<?php

namespace App\Http\Requests\Taskings\Attachments;

use App\Models\TaskingAttachment;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** Removing an attachment needs a reason (Phase 11H). */
class TaskingAttachmentDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();
        /** @var TaskingAttachment $attachment */
        $attachment = $this->route('attachment');

        return $user->can('delete', $attachment);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000']];
    }
}
