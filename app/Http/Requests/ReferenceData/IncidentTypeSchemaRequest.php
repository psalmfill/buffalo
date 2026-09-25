<?php

namespace App\Http\Requests\ReferenceData;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An incident type's detail fields. The schema's contents are validated by
 * SchemaValidator in UpdateIncidentTypeSchema (the authoritative check);
 * this request only requires that one was sent. Authorisation is in the
 * controller.
 */
class IncidentTypeSchemaRequest extends FormRequest
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
            'schema' => ['present', 'array'],
        ];
    }

    /**
     * The schema as sent (empty strings already null), for SchemaValidator.
     */
    public function schema(): mixed
    {
        return $this->input('schema');
    }
}
