<?php

namespace App\Http\Requests\LocalGovernment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The required reason for deleting an intelligence record (communities,
 * religious institutions, figures, security notes), recorded in the audit
 * log. Authorisation is in the controller (the model's policy).
 */
class DeleteRecordRequest extends FormRequest
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
