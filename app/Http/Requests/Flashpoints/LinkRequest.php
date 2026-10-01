<?php

namespace App\Http\Requests\Flashpoints;

use App\Enums\IncidentStatus;
use App\Models\CommunitySecurityNote;
use App\Models\Incident;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Link an incident or a community security note to a flashpoint (Phase
 * 6A), by ULID. The record is looked up through the user's own scopes, so
 * only visible records can be linked; drafts and invalid incidents cannot.
 * Authorisation (FlashpointPolicy::linkIncident / linkSecurityNote) is in
 * the controller.
 */
class LinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function incident(): ?Incident
    {
        $ulid = $this->input('record');

        return is_string($ulid) ? Incident::query()->where('ulid', $ulid)->first() : null;
    }

    public function securityNote(): ?CommunitySecurityNote
    {
        $ulid = $this->input('record');

        return is_string($ulid) ? CommunitySecurityNote::query()->where('ulid', $ulid)->first() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $incidents = $this->route()?->getName() === 'flashpoints.incidents.store';

        return [
            'record' => ['required', 'string', 'size:26', function (string $attribute, mixed $value, Closure $fail) use ($incidents): void {
                if (! $incidents) {
                    if ($this->securityNote() === null) {
                        $fail('Choose a security note you can see.');
                    }

                    return;
                }

                $incident = $this->incident();

                if ($incident === null) {
                    $fail('Choose an incident you can see.');
                } elseif (in_array($incident->status, [IncidentStatus::Draft, IncidentStatus::Invalid], true)) {
                    $fail('Drafts and invalid incidents cannot be linked to a flashpoint.');
                }
            }],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function note(): ?string
    {
        $note = trim((string) $this->validated('note'));

        return $note === '' ? null : $note;
    }
}
