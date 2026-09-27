<?php

namespace App\Http\Requests\AccessRequests;

use Illuminate\Foundation\Http\FormRequest;

/** Revoke a grant: a reason is required. */
class RevokeGrantRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'min:5', 'max:1000']];
    }
}
