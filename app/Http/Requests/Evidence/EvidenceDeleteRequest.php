<?php

namespace App\Http\Requests\Evidence;

use App\Models\IncidentEvidence;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing an evidence item needs a reason (Phase 5B).
 */
class EvidenceDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();
        /** @var IncidentEvidence $evidence */
        $evidence = $this->route('evidence');

        return $user->can('delete', $evidence);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:10', 'max:1000']];
    }
}
