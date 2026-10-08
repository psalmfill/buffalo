<?php

namespace App\Http\Requests\Persons;

use Illuminate\Foundation\Http\FormRequest;

/** An LGA chief or Deputy State Director asks for a merge (Phase 10A.7). */
class StorePersonMergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the action authorises both persons
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'person' => ['required', 'string', 'size:26'],
            'other' => ['required', 'string', 'size:26', 'different:person'],
            'suggested_survivor' => ['nullable', 'string', 'size:26'],
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['reason.min' => 'Explain in at least 20 characters why these are the same person.'];
    }
}
