<?php

namespace App\Http\Requests\Political;

use App\Enums\PoliticalOfficeType;
use App\Models\Lga;
use App\Models\PoliticalOffice;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\User;
use App\Models\Ward;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Jurisdiction\JurisdictionOptions;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Record or edit a political office. Per office type: a senator needs a
 * senatorial district, LGA chairmen, vice chairmen and councillors need an
 * LGA, councillors a ward; district and LGA belong to the state, the ward
 * to the LGA (and an LGA given with a district lies in it). The office must
 * end up within the user's jurisdiction (the rule users will see it by).
 * Authorisation is in the controller.
 */
class PoliticalOfficeRequest extends FormRequest
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
        $office = PoliticalOfficeType::tryFrom((string) $this->input('office'));

        return [
            'office' => ['required', Rule::enum(PoliticalOfficeType::class)],
            'office_title' => [$office === PoliticalOfficeType::Other ? 'required' : 'nullable', 'string', 'max:150'],
            'party' => ['nullable', 'string', 'max:100'],
            'state' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->state() === null) {
                    $fail('Choose a state within your jurisdiction.');
                }
            }],
            'senatorial_district' => [$office?->requiresDistrict() ? 'required' : 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->state() !== null && $this->district() === null) {
                    $fail('The senatorial district must be in the selected state.');
                }
            }],
            'lga' => [$office?->requiresLga() ? 'required' : 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->state() === null) {
                    return;
                }

                $lga = $this->lga();

                if ($lga === null) {
                    $fail('The LGA must be in the selected state.');
                } elseif ($this->district() !== null && $lga->senatorial_district_id !== $this->district()->id) {
                    $fail('The LGA must be in the selected senatorial district.');
                }
            }],
            'ward' => [$office?->requiresWard() ? 'required' : 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() !== null && $this->ward() === null) {
                    $fail('The ward must be in the selected LGA.');
                }
            }],
            'constituency_name' => ['nullable', 'string', 'max:150'],
            'started_on' => ['required', 'date_format:Y-m-d'],
            'ended_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:started_on'],
        ];
    }

    /**
     * The office as it would be saved must be visible to the user (an LGA
     * chief: in their LGA, or a senator of their district).
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $probe = (new PoliticalOffice)->forceFill([
                'state_id' => $this->state()?->id,
                'senatorial_district_id' => $this->district()?->id,
                'lga_id' => $this->lga()?->id,
            ]);
            /** @var User $user */
            $user = $this->user();

            if (! app(JurisdictionGate::class)->canAccess($user, $probe)) {
                $validator->errors()->add('state', 'This office would be outside your jurisdiction. LGA officers record offices of their LGA, or the senator of their district.');
            }
        }];
    }

    public function state(): ?State
    {
        $ulid = $this->input('state');

        if (! is_string($ulid)) {
            return null;
        }

        /** @var User $user */
        $user = $this->user();

        return app(JurisdictionOptions::class)->states($user)->firstWhere('ulid', $ulid);
    }

    public function district(): ?SenatorialDistrict
    {
        $ulid = $this->input('senatorial_district');
        $state = $this->state();

        return is_string($ulid) && $state !== null
            ? SenatorialDistrict::query()->where('state_id', $state->id)->where('ulid', $ulid)->first()
            : null;
    }

    public function lga(): ?Lga
    {
        $ulid = $this->input('lga');
        $state = $this->state();

        return is_string($ulid) && $state !== null
            ? Lga::query()->where('state_id', $state->id)->where('ulid', $ulid)->first()
            : null;
    }

    public function ward(): ?Ward
    {
        $ulid = $this->input('ward');
        $lga = $this->lga();

        return is_string($ulid) && $lga !== null
            ? Ward::query()->where('lga_id', $lga->id)->where('ulid', $ulid)->first()
            : null;
    }

    /**
     * Validated values with places resolved to ids.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->validated();

        return [
            'office' => $data['office'],
            'office_title' => $data['office_title'] ?? null,
            'party' => $data['party'] ?? null,
            'state_id' => $this->state()?->id,
            'senatorial_district_id' => $this->district()?->id,
            'lga_id' => $this->lga()?->id,
            'ward_id' => $this->ward()?->id,
            'constituency_name' => $data['constituency_name'] ?? null,
            'started_on' => $data['started_on'],
            'ended_on' => $data['ended_on'] ?? null,
        ];
    }
}
