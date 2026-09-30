<?php

namespace App\Http\Requests\AccessRequests;

use App\Enums\GrantScope;
use App\Enums\RequestUrgency;
use App\Models\Incident;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request access to a hit (Phase 3C). The record is named only by the hit
 * token, which RequestAccess exchanges server-side; there is no record
 * identifier to send. Authorisation is in the controller.
 */
class AccessRequestRequest extends FormRequest
{
    public const MIN_REASON = 30;

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
            'token' => ['required', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:'.self::MIN_REASON, 'max:2000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'scope' => ['required', Rule::enum(GrantScope::class)],
            'urgency' => ['required', Rule::enum(RequestUrgency::class)],
            'requested_days' => ['required', 'integer', Rule::in([7, 14, 30])],
            // The requester's own incident, cited as the reason (Phase 5A):
            // it must be an incident the requester can see.
            'incident' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $incident = $this->incident();
                /** @var User $user */
                $user = $this->user();

                if ($incident === null || ! $user->can('view', $incident)) {
                    $fail('Choose an incident you can view.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.min' => 'Explain the reason in at least :min characters.'];
    }

    /**
     * @return array{reason: string, reference: string|null, scope: string, urgency: string, requested_days: int, incident_id: int|null}
     */
    public function values(): array
    {
        $data = $this->validated();

        return [
            'reason' => (string) $data['reason'],
            'reference' => isset($data['reference']) ? (string) $data['reference'] : null,
            'scope' => (string) $data['scope'],
            'urgency' => (string) $data['urgency'],
            'requested_days' => (int) $data['requested_days'],
            'incident_id' => $this->incident()?->id,
        ];
    }

    public function incident(): ?Incident
    {
        $ulid = $this->input('incident');

        return is_string($ulid) ? Incident::query()->where('ulid', $ulid)->first() : null;
    }
}
