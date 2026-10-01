<?php

namespace App\Http\Requests\Incidents;

use App\Enums\Classification;
use App\Enums\IncidentUpdateType;
use App\Models\Incident;
use App\Support\Incidents\ChildClassification;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A follow-up (Phase 5C): its type, text, and a classification at least the
 * incident's and within the author's clearance.
 */
class IncidentUpdateRequest extends FormRequest
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
            'type' => ['required', Rule::enum(IncidentUpdateType::class)],
            'body' => ['required', 'string', 'max:20000'],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                /** @var Incident $incident */
                $incident = $this->route('incident');
                $problem = ChildClassification::problem($this->user(), $incident, Classification::tryFrom((string) $value), 'A follow-up');

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
        ];
    }
}
