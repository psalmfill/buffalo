<?php

namespace App\Http\Requests\LocalGovernment;

use App\Enums\CommunityType;
use App\Models\Community;
use App\Models\Lga;
use App\Models\PersonRoleType;
use App\Models\Ward;
use App\Rules\ActiveOption;
use App\Rules\PersonWithRole;
use App\Support\LocalGovernment\CommunityRules;
use App\Support\LocalGovernment\Nigeria;
use App\Support\Persons\LinkedPerson;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a community in an LGA (/lgas/{lga}/communities) or edit one
 * (/communities/{community}). The LGA comes from the URL (bound within the
 * user's jurisdiction) and cannot change. Authorisation is in the
 * controller.
 */
class CommunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function community(): ?Community
    {
        $community = $this->route('community');

        return $community instanceof Community ? $community : null;
    }

    public function lga(): Lga
    {
        $lga = $this->route('lga') ?? $this->community()?->lga;

        abort_unless($lga instanceof Lga, 404);

        return $lga;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $lga = $this->lga();
        $rules = app(CommunityRules::class);
        $community = $this->community();

        return [
            'name' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($lga, $rules, $community): void {
                if (is_string($value) && $rules->sameName($lga, $value, $community?->id) !== null) {
                    $fail('A community with this name already exists in this LGA.');
                }
            }],
            'other_names' => ['present', 'array', 'max:20'],
            // Blank entries arrive as null (input middleware) and are dropped.
            'other_names.*' => ['nullable', 'string', 'max:255', 'distinct:ignore_case'],
            'type' => ['required', Rule::enum(CommunityType::class)],
            'ward' => ['nullable', 'string', new ActiveOption(Ward::class, $community?->ward?->ulid), function (string $attribute, mixed $value, Closure $fail) use ($lga, $rules): void {
                if (is_string($value) && $rules->ward($lga, 'ulid', $value) === null) {
                    $fail("The ward must be a ward of {$lga->name} LGA.");
                }
            }],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:'.Nigeria::MIN_LATITUDE.','.Nigeria::MAX_LATITUDE],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:'.Nigeria::MIN_LONGITUDE.','.Nigeria::MAX_LONGITUDE, function (string $attribute, mixed $value, Closure $fail) use ($lga, $rules): void {
                $latitude = $this->input('latitude');

                if (is_numeric($value) && is_numeric($latitude)) {
                    $error = $rules->locationError($lga, (float) $latitude, (float) $value);

                    if ($error !== null) {
                        $fail($error);
                    }
                }
            }],
            'population_estimate' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Absent: unchanged (e.g. a ruler the user cannot see).
            'traditional_ruler' => ['sometimes', 'nullable', 'string', new PersonWithRole(PersonRoleType::TRADITIONAL_RULER, $community?->traditionalRuler?->ulid)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.between' => 'The latitude must be within Nigeria (:min to :max).',
            'longitude.between' => 'The longitude must be within Nigeria (:min to :max).',
        ];
    }

    /**
     * Validated values for SaveCommunity (ward ULID resolved).
     *
     * @return array{name: string, other_names: list<string>, type: string, ward_id: int|null, latitude: float|null, longitude: float|null, population_estimate: int|null, notes: string|null, traditional_ruler_id?: int|null}
     */
    public function values(): array
    {
        $data = $this->validated();

        return [
            'name' => (string) $data['name'],
            'other_names' => array_values(array_filter(
                $data['other_names'] ?? [],
                fn (mixed $name): bool => is_string($name) && trim($name) !== '',
            )),
            'type' => (string) $data['type'],
            'ward_id' => isset($data['ward']) ? app(CommunityRules::class)->ward($this->lga(), 'ulid', $data['ward'])?->id : null,
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'population_estimate' => isset($data['population_estimate']) ? (int) $data['population_estimate'] : null,
            'notes' => $data['notes'] ?? null,
            ...($this->has('traditional_ruler') ? ['traditional_ruler_id' => LinkedPerson::id(isset($data['traditional_ruler']) ? (string) $data['traditional_ruler'] : null)] : []),
        ];
    }
}
