<?php

namespace App\Http\Requests\Political;

use Illuminate\Foundation\Http\FormRequest;

/** Reassigning an office to the right person (Phase 10B.3). */
class ReassignPoliticalOfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller and the action authorise
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'person' => ['required', 'string', 'size:26'],
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['reason.min' => 'Explain the correction in at least 20 characters.'];
    }
}
