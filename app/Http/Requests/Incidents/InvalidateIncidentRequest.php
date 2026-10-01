<?php

namespace App\Http\Requests\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\InvalidReason;
use App\Models\Incident;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Marking an incident invalid (Phase 5C): a reason; a note for "other"; for
 * a duplicate, the original, which the user must be able to see and which
 * must not be invalid itself.
 */
class InvalidateIncidentRequest extends FormRequest
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
            'reason' => ['required', Rule::enum(InvalidReason::class)],
            'note' => ['nullable', 'string', 'max:5000', 'required_if:reason,other'],
            'duplicate_of' => ['nullable', 'string', 'required_if:reason,duplicate', 'prohibited_unless:reason,duplicate', function (string $attribute, mixed $value, Closure $fail): void {
                $original = $this->duplicateOf();
                /** @var Incident $incident */
                $incident = $this->route('incident');

                if ($original === null || $original->id === $incident->id || $original->status === IncidentStatus::Invalid) {
                    $fail('Choose the original incident: one you can view that is not itself invalid.');
                }
            }],
        ];
    }

    /** Found through the scopes, so only an incident the user can see. */
    public function duplicateOf(): ?Incident
    {
        $ulid = $this->input('duplicate_of');

        return is_string($ulid) && $ulid !== '' ? Incident::query()->where('ulid', $ulid)->first() : null;
    }

    public function note(): ?string
    {
        $note = trim((string) $this->validated('note'));

        return $note === '' ? null : $note;
    }
}
