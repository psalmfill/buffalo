<?php

namespace App\Http\Requests\Threats;

use App\Enums\IncidentStatus;
use App\Enums\ThreatStatus;
use App\Models\Incident;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mark a threat report mitigated, materialised or withdrawn (Phase 6B),
 * with the required note; for materialised, optionally the incident it
 * materialised as (visible to the officer, not a draft or invalid).
 * Authorisation is in the controller.
 */
class ThreatStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function incident(): ?Incident
    {
        $ulid = $this->input('incident');

        return is_string($ulid) ? Incident::query()->where('ulid', $ulid)->first() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_map(fn (ThreatStatus $s) => $s->value, ThreatStatus::settable()))],
            'note' => ['required', 'string', 'max:2000'],
            'incident' => ['nullable', 'string', 'size:26', function (string $attribute, mixed $value, Closure $fail): void {
                $incident = $this->incident();

                if ($incident === null) {
                    $fail('Choose an incident you can see.');
                } elseif (in_array($incident->status, [IncidentStatus::Draft, IncidentStatus::Invalid], true)) {
                    $fail('Drafts and invalid incidents cannot be linked to a threat report.');
                }
            }],
        ];
    }

    public function status(): ThreatStatus
    {
        return ThreatStatus::from((string) $this->validated('status'));
    }

    public function note(): string
    {
        return trim((string) $this->validated('note'));
    }
}
