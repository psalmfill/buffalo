<?php

namespace App\Http\Requests\PersonsOfInterest;

use App\Enums\EyeColour;
use App\Enums\HairColour;
use App\Enums\MaritalStatus;
use App\Enums\PoiStatus;
use App\Enums\RiskLevel;
use App\Enums\TribalMarks;
use App\Models\Lga;
use App\Models\PersonOfInterest;
use App\Models\Religion;
use App\Models\State;
use App\Models\ThreatType;
use App\Rules\RichTextRule;
use App\Support\LocalGovernment\Nigeria;
use Closure;
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
            // Personal details (Phase 11G.4), all optional.
            'criminal_record' => ['nullable', new RichTextRule(20000)],
            'height_cm' => ['nullable', 'integer', 'between:50,250'],
            'height_feet' => ['nullable', 'integer', 'between:1,8', 'prohibits:height_cm'],
            'height_inches' => ['nullable', 'integer', 'between:0,11', 'required_with:height_feet'],
            'origin_state' => ['nullable', 'string', Rule::exists('states', 'ulid')],
            'origin_lga' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $state = $this->input('origin_state');

                if (! is_string($state) || ! Lga::query()->where('ulid', (string) $value)->whereIn('state_id', State::query()->where('ulid', $state)->select('id'))->exists()) {
                    $fail('The LGA of origin must be an LGA of the state of origin.');
                }
            }],
            'hometown' => ['nullable', 'string', 'max:255'],
            'marital_status' => ['nullable', Rule::enum(MaritalStatus::class)],
            'religion' => ['nullable', 'string', Rule::in($this->religions())],
            'political_affiliations' => ['nullable', 'string', 'max:1000'],
            'hair_colour' => ['nullable', Rule::enum(HairColour::class)],
            'eye_colour' => ['nullable', Rule::enum(EyeColour::class)],
            'has_tribal_marks' => ['nullable', Rule::enum(TribalMarks::class)],
            'tribal_marks_description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Religion codes from the religions lookup: active ones, or the one the
     * record already has.
     *
     * @return list<string>
     */
    private function religions(): array
    {
        return array_values(array_unique(array_filter([
            ...Religion::query()->where('is_active', true)->pluck('code')->all(),
            $this->poi()?->religion,
        ])));
    }

    /**
     * Validated values for DesignatePersonOfInterest and SavePoiDetails:
     * the origin as ids, the height in centimetres (from feet and inches
     * when given that way).
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->validated();
        $feet = $data['height_feet'] ?? null;

        if ($feet !== null) {
            $data['height_cm'] = (int) round(((int) $feet * 12 + (int) ($data['height_inches'] ?? 0)) * 2.54);
        }

        unset($data['height_feet'], $data['height_inches']);
        $data['origin_state_id'] = isset($data['origin_state']) ? State::query()->where('ulid', $data['origin_state'])->value('id') : null;
        $data['origin_lga_id'] = isset($data['origin_lga']) ? Lga::query()->where('ulid', $data['origin_lga'])->value('id') : null;
        unset($data['origin_state'], $data['origin_lga']);

        return $data;
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
