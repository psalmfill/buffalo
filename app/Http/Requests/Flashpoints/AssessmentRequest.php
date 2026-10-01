<?php

namespace App\Http\Requests\Flashpoints;

use App\Enums\ConcernLevel;
use App\Enums\FlashpointStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A flashpoint assessment (Phase 6A). Authorisation is in the controller.
 */
class AssessmentRequest extends FormRequest
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
            'concern_level' => ['required', Rule::enum(ConcernLevel::class)],
            'status' => ['required', Rule::enum(FlashpointStatus::class)],
            'assessment' => ['required', 'string', 'max:10000'],
            'recommended_action' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array{concern_level: string, status: string, assessment: string, recommended_action: string|null}
     */
    public function values(): array
    {
        return self::normalise($this->validated());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{concern_level: string, status: string, assessment: string, recommended_action: string|null}
     */
    public static function normalise(array $data): array
    {
        $action = isset($data['recommended_action']) ? trim((string) $data['recommended_action']) : '';

        return [
            'concern_level' => (string) $data['concern_level'],
            'status' => (string) $data['status'],
            'assessment' => trim((string) $data['assessment']),
            'recommended_action' => $action === '' ? null : $action,
        ];
    }
}
