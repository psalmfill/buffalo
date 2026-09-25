<?php

namespace App\Http\Requests\LocalGovernment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The required reason for deleting a community (recorded in the audit
 * log). Authorisation is in the controller (CommunityPolicy::delete).
 */
class DeleteCommunityRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
