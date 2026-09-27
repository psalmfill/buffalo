<?php

namespace App\Http\Requests\AccessRequests;

use Illuminate\Foundation\Http\FormRequest;

/** Reject an access request: a note is required. */
class RejectRequest extends FormRequest
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
        return ['note' => ['required', 'string', 'min:5', 'max:1000']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['note.required' => 'Give the reason for rejecting the request.'];
    }
}
