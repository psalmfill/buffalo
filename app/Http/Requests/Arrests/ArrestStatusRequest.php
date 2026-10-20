<?php

namespace App\Http\Requests\Arrests;

use App\Enums\ArrestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A status change for an arrest, or for one arrested person (`person`, the
 * arrested-person row's ULID), always with a comment (Phase 11C).
 * Authorisation is in the controller.
 */
class ArrestStatusRequest extends FormRequest
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
        return [
            'person' => ['nullable', 'string', 'size:26'],
            'status' => ['required', Rule::enum(ArrestStatus::class)],
            'comment' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['comment.required' => 'Explain the change of status.'];
    }
}
