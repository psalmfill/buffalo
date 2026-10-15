<?php

namespace App\Http\Requests\Incidents;

use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The text a workflow step asks for (Phase 5C): a required reason or
 * summary, or an optional comment, depending on the step. Authorisation is
 * done in the controller through IncidentPolicy.
 */
class IncidentTransitionRequest extends FormRequest
{
    /** Steps whose text is required. */
    private const REQUIRED = [
        'incidents.return' => 'reason',
        'incidents.reopen' => 'reason',
        'incidents.escalate' => 'reason',
        'incidents.resolve' => 'summary',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = self::REQUIRED[(string) $this->route()?->getName()] ?? null;

        return [
            'reason' => $required === 'reason' ? ['required', 'string', 'min:5', 'max:5000'] : ['prohibited'],
            'summary' => $required === 'summary' ? ['required', new RichTextRule(10000, required: true, min: 10)] : ['prohibited'],
            'comment' => $required === null ? ['nullable', 'string', 'max:5000'] : ['prohibited'],
            'confirm_duplicates' => ['sometimes', 'boolean'],
        ];
    }

    public function text(string $key): ?string
    {
        $value = $this->validated($key);
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
