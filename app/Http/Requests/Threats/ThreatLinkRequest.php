<?php

namespace App\Http\Requests\Threats;

use App\Enums\IncidentStatus;
use App\Enums\ThreatGroupRole;
use App\Enums\ThreatIncidentRelation;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\Incident;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Link a flashpoint, criminal group (with role) or incident (with relation)
 * to a threat report (Phase 6B), by ULID, looked up through the officer's
 * own scopes. Incidents must not be drafts or invalid. The kind of record
 * comes from the route. Authorisation is in the controller.
 */
class ThreatLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function kind(): string
    {
        return (string) $this->route('kind');
    }

    public function record(): Flashpoint|CriminalGroup|Incident|null
    {
        $ulid = $this->input('record');

        if (! is_string($ulid)) {
            return null;
        }

        return match ($this->kind()) {
            'flashpoints' => Flashpoint::query()->where('ulid', $ulid)->first(),
            'groups' => CriminalGroup::query()->where('ulid', $ulid)->first(),
            'incidents' => Incident::query()->where('ulid', $ulid)->first(),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'record' => ['required', 'string', 'size:26', function (string $attribute, mixed $value, Closure $fail): void {
                $record = $this->record();

                if ($record === null) {
                    $fail('Choose a record you can see.');
                } elseif ($record instanceof Incident && in_array($record->status, [IncidentStatus::Draft, IncidentStatus::Invalid], true)) {
                    $fail('Drafts and invalid incidents cannot be linked to a threat report.');
                }
            }],
            'role' => $this->kind() === 'groups' ? ['required', Rule::enum(ThreatGroupRole::class)] : ['prohibited'],
            'relation' => $this->kind() === 'incidents' ? ['required', Rule::enum(ThreatIncidentRelation::class)] : ['prohibited'],
        ];
    }

    public function linkKind(): ?string
    {
        $value = $this->validated('role') ?? $this->validated('relation');

        return is_string($value) ? $value : null;
    }
}
