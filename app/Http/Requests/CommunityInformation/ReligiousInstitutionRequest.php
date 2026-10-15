<?php

namespace App\Http\Requests\CommunityInformation;

use App\Enums\ReligiousInstitutionType;
use App\Models\Community;
use App\Models\Denomination;
use App\Models\PersonRoleType;
use App\Models\Religion;
use App\Models\ReligiousInstitution;
use App\Rules\ActiveOption;
use App\Rules\PersonWithRole;
use App\Rules\RichTextRule;
use App\Support\LocalGovernment\CommunityRules;
use App\Support\LocalGovernment\Nigeria;
use App\Support\Persons\LinkedPerson;
use Closure;
use Illuminate\Validation\Rule;

class ReligiousInstitutionRequest extends LgaRecordRequest
{
    protected function recordParameter(): string
    {
        return 'institution';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $record = $this->record();
        $current = $record instanceof ReligiousInstitution ? $record : null;

        return [
            'community' => ['required', 'string', $this->communityOfLga()],
            'religion' => ['required', 'string', new ActiveOption(Religion::class, $current?->religion->ulid)],
            'denomination' => ['nullable', 'string', new ActiveOption(Denomination::class, $current?->denomination?->ulid), function (string $attribute, mixed $value, Closure $fail): void {
                $belongs = Denomination::query()->where('ulid', $value)
                    ->whereHas('religion', fn ($religion) => $religion->where('ulid', $this->input('religion')))
                    ->exists();

                if (! $belongs) {
                    $fail('The denomination must belong to the selected religion.');
                }
            }],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(ReligiousInstitutionType::class)],
            'estimated_congregation' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:'.Nigeria::MIN_LATITUDE.','.Nigeria::MAX_LATITUDE],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:'.Nigeria::MIN_LONGITUDE.','.Nigeria::MAX_LONGITUDE, function (string $attribute, mixed $value, Closure $fail): void {
                $latitude = $this->input('latitude');

                if (is_numeric($value) && is_numeric($latitude)) {
                    $error = app(CommunityRules::class)->locationError($this->lga(), (float) $latitude, (float) $value);

                    if ($error !== null) {
                        $fail($error);
                    }
                }
            }],
            'notes' => ['nullable', new RichTextRule(5000)],
            // Absent: unchanged (e.g. a leader the user cannot see).
            'leader' => ['sometimes', 'nullable', 'string', new PersonWithRole(PersonRoleType::RELIGIOUS_LEADER, $current?->leader?->ulid)],
        ];
    }

    /**
     * @return array{community: Community, religion_id: int, denomination_id: int|null, name: string, type: string, estimated_congregation: int|null, latitude: float|null, longitude: float|null, notes: string|null, leader_person_id?: int|null}
     */
    public function values(): array
    {
        $data = $this->validated();
        /** @var Community $community */
        $community = $this->community();

        return [
            'community' => $community,
            'religion_id' => (int) Religion::query()->where('ulid', $data['religion'])->value('id'),
            'denomination_id' => isset($data['denomination']) ? (int) Denomination::query()->where('ulid', $data['denomination'])->value('id') : null,
            'name' => (string) $data['name'],
            'type' => (string) $data['type'],
            'estimated_congregation' => isset($data['estimated_congregation']) ? (int) $data['estimated_congregation'] : null,
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'notes' => $data['notes'] ?? null,
            ...($this->has('leader') ? ['leader_person_id' => LinkedPerson::id(isset($data['leader']) ? (string) $data['leader'] : null)] : []),
        ];
    }
}
