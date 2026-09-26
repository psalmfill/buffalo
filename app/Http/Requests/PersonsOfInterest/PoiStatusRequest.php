<?php

namespace App\Http\Requests\PersonsOfInterest;

use App\Enums\PoiStatus;
use App\Enums\RiskLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change a POI's status and/or risk level. A comment is always required.
 * Setting "cleared" also needs poi.clear (checked in the controller).
 */
class PoiStatusRequest extends FormRequest
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
            'status' => ['nullable', 'required_without:risk_level', Rule::enum(PoiStatus::class)],
            'risk_level' => ['nullable', 'required_without:status', Rule::enum(RiskLevel::class)],
            'comment' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comment.required' => 'A comment is required for every status or risk change.',
            'comment.min' => 'Write a short comment (at least 5 characters).',
        ];
    }

    public function status(): ?PoiStatus
    {
        return PoiStatus::tryFrom((string) $this->validated('status'));
    }

    public function risk(): ?RiskLevel
    {
        return RiskLevel::tryFrom((string) $this->validated('risk_level'));
    }
}
