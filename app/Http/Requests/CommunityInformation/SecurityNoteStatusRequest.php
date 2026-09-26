<?php

namespace App\Http\Requests\CommunityInformation;

use App\Enums\SecurityNoteStatus;
use App\Models\CommunitySecurityNote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change a security note's status. Authorisation is in the controller.
 */
class SecurityNoteStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $note = $this->route('note');
        $observed = $note instanceof CommunitySecurityNote ? $note->observed_at->toDateString() : null;

        return [
            'status' => ['required', Rule::enum(SecurityNoteStatus::class)],
            'resolved_at' => array_filter([
                'nullable',
                'required_if:status,resolved',
                'date_format:Y-m-d',
                'before_or_equal:today',
                $observed !== null ? 'after_or_equal:'.$observed : null,
            ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resolved_at.required_if' => 'Give the date the matter was resolved.',
            'resolved_at.after_or_equal' => 'The resolution date cannot be before the date it was observed.',
        ];
    }
}
