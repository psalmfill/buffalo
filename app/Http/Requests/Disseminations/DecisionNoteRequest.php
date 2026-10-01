<?php

namespace App\Http\Requests\Disseminations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A required note: revoking a dissemination, declining a request (Phase 7A).
 */
class DecisionNoteRequest extends FormRequest
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
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function note(): string
    {
        return trim((string) $this->validated('note'));
    }
}
