<?php

namespace App\Http\Requests\Taskings;

use App\Models\Tasking;
use App\Models\TaskingRecipient;
use App\Models\User;
use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One rich text field of a tasking's follow-up (Phase 11H): a follow-up
 * note (`body`), a completion report (`response`) or a further response
 * (`body`), each required and limited by its plain text. Controllers
 * authorise (TaskingPolicy).
 */
class TaskingTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();
        $tasking = $this->route('tasking');
        $recipient = $this->route('recipient');

        // Before validation (TaskingPolicy).
        return match (true) {
            $tasking instanceof Tasking => $user->can('addNote', $tasking),
            $recipient instanceof TaskingRecipient => $user->can('respond', $recipient),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $field = $this->field();
        $max = (int) config($this->routeIs('taskings.notes.store') ? 'buffalo.taskings.note_max_characters' : 'buffalo.taskings.response_max_characters');

        return [$field => ['required', new RichTextRule($max, required: true)]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'response.required' => 'Write your report back before marking the tasking completed.',
            'body.required' => $this->routeIs('taskings.notes.store') ? 'Write the follow-up note.' : 'Write your response.',
        ];
    }

    public function field(): string
    {
        return $this->routeIs('tasking-recipients.complete') ? 'response' : 'body';
    }

    public function text(): string
    {
        return (string) $this->validated($this->field());
    }
}
