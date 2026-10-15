<?php

namespace App\Http\Requests\PersonsOfInterest;

use App\Enums\PoiStatus;
use App\Enums\RiskLevel;
use App\Models\PersonOfInterest;
use App\Models\ThreatType;
use App\Rules\RichTextRule;
use App\Support\LocalGovernment\Nigeria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Designate a person of interest (/persons/{person}/person-of-interest) or
 * edit a POI's details (/persons-of-interest/{poi}). Offence categories are
 * threat type codes: active ones, or ones the record already had. Risk and
 * status are set on designation only (with a comment); later changes go
 * through PoiStatusRequest. Authorisation is in the controller.
 */
class PoiDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function poi(): ?PersonOfInterest
    {
        $poi = $this->route('poi');

        return $poi instanceof PersonOfInterest ? $poi : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $designating = $this->poi() === null;
        $allowed = ThreatType::query()->where('is_active', true)->pluck('code')
            ->merge($this->poi()->offence_categories ?? [])
            ->unique()->values()->all();

        return [
            'offence_categories' => ['present', 'array', 'max:20'],
            'offence_categories.*' => ['string', 'distinct', Rule::in($allowed)],
            'known_offences' => ['required', new RichTextRule(10000, required: true)],
            'modus_operandi' => ['nullable', new RichTextRule(10000)],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:'.Nigeria::MIN_LATITUDE.','.Nigeria::MAX_LATITUDE],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:'.Nigeria::MIN_LONGITUDE.','.Nigeria::MAX_LONGITUDE],
            'last_known_location_description' => ['nullable', 'string', 'max:2000'],
            'last_sighted_at' => ['nullable', 'date', 'before_or_equal:now'],
            'risk_level' => $designating ? ['required', Rule::enum(RiskLevel::class)] : ['prohibited'],
            // Not "cleared": designating someone already cleared makes no sense.
            'status' => $designating ? ['required', Rule::enum(PoiStatus::class)->except([PoiStatus::Cleared])] : ['prohibited'],
            'comment' => $designating ? ['required', 'string', 'min:5', 'max:1000'] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'offence_categories.*.in' => 'Choose offence categories from the list of threat types.',
            'comment.required' => 'Give the grounds for the designation.',
            'risk_level.prohibited' => 'Change the risk level with the status action (a comment is required).',
            'status.prohibited' => 'Change the status with the status action (a comment is required).',
        ];
    }
}
