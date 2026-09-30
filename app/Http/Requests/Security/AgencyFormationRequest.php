<?php

namespace App\Http\Requests\Security;

use App\Enums\Classification;
use App\Enums\FormationStatus;
use App\Enums\FormationType;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\Lga;
use App\Models\SecurityAgency;
use App\Models\User;
use App\Rules\ActiveOption;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\LocalGovernment\CommunityRules;
use App\Support\LocalGovernment\Nigeria;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Create an agency formation (agency and LGA chosen once, fixed afterwards)
 * or edit one. The parent must be a formation of the same agency the user
 * can see, and never the formation itself or one of its descendants.
 * `parent` absent on edit = unchanged (e.g. a parent the user cannot see).
 * Authorisation is in the controller.
 */
class AgencyFormationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function formation(): ?AgencyFormation
    {
        $formation = $this->route('formation');

        return $formation instanceof AgencyFormation ? $formation : null;
    }

    public function lga(): ?Lga
    {
        $current = $this->formation();

        if ($current !== null) {
            return $current->lga;
        }

        $ulid = $this->input('lga');

        if (! is_string($ulid)) {
            return null;
        }

        /** @var User $user */
        $user = $this->user();

        return app(JurisdictionOptions::class)->lgaQuery($user)->active()->where('ulid', $ulid)->first();
    }

    public function agency(): ?SecurityAgency
    {
        $current = $this->formation();

        if ($current !== null) {
            return $current->agency;
        }

        $ulid = $this->input('agency');

        return is_string($ulid) ? SecurityAgency::query()->where('ulid', $ulid)->first() : null;
    }

    public function parent(): ?AgencyFormation
    {
        $ulid = $this->input('parent');

        return is_string($ulid) ? AgencyFormation::query()->where('ulid', $ulid)->first() : null;
    }

    public function community(): ?Community
    {
        $lga = $this->lga();

        return $lga === null ? null : app(CommunityRules::class)->communityOf($lga, $this->input('community'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editing = $this->formation() !== null;

        return [
            'lga' => $editing ? ['prohibited'] : ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() === null) {
                    $fail('Choose an LGA within your jurisdiction.');
                }
            }],
            'agency' => $editing ? ['prohibited'] : ['required', 'string', new ActiveOption(SecurityAgency::class), function (string $attribute, mixed $value, Closure $fail): void {
                $agency = $this->agency();
                $lga = $this->lga();

                if ($agency !== null && $lga !== null && $agency->state_id !== null && $agency->state_id !== $lga->state_id) {
                    $fail("{$agency->name} is a state agency: its formations must be in its own state.");
                }
            }],
            'parent' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $this->validateParent($fail);
            }],
            'community' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() !== null && $this->community() === null) {
                    $fail('The community must be a community of the formation\'s LGA.');
                }
            }],
            'name' => ['required', 'string', 'max:200'],
            'formation_type' => ['required', Rule::enum(FormationType::class)],
            'address' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:'.Nigeria::MIN_LATITUDE.','.Nigeria::MAX_LATITUDE],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:'.Nigeria::MIN_LONGITUDE.','.Nigeria::MAX_LONGITUDE, function (string $attribute, mixed $value, Closure $fail): void {
                $latitude = $this->input('latitude');
                $lga = $this->lga();

                if ($lga !== null && is_numeric($value) && is_numeric($latitude)) {
                    $error = app(CommunityRules::class)->locationError($lga, (float) $latitude, (float) $value);

                    if ($error !== null) {
                        $fail($error);
                    }
                }
            }],
            'official_phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]+$/'],
            'commander_title' => ['nullable', 'string', 'max:100'],
            'commander_name' => ['nullable', 'string', 'max:200'],
            'commander_rank' => ['nullable', 'string', 'max:100'],
            'personnel_strength' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_temporary' => ['required', 'boolean'],
            'active_from' => ['nullable', 'required_if_accepted:is_temporary', 'date_format:Y-m-d'],
            'active_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:active_from'],
            'status' => ['required', Rule::enum(FormationStatus::class)],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $level = Classification::tryFrom((string) $value);
                /** @var User $user */
                $user = $this->user();

                if ($level !== null && ! app(ClassificationGate::class)->canAssign($user, $level)) {
                    $fail('You cannot classify a record above your own clearance.');
                }
            }],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lga.prohibited' => 'A formation\'s LGA cannot be changed.',
            'agency.prohibited' => 'A formation\'s agency cannot be changed.',
            'active_from.required_if_accepted' => 'A temporary formation needs the date it became active.',
            'official_phone.regex' => 'Enter a phone number using digits, spaces, +, - and brackets only.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->validated();
        $temporary = (bool) $data['is_temporary'];

        return [
            'community_id' => $this->community()?->id,
            'name' => trim((string) $data['name']),
            'formation_type' => (string) $data['formation_type'],
            'address' => self::text($data['address'] ?? null),
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'official_phone' => self::text($data['official_phone'] ?? null),
            'commander_title' => self::text($data['commander_title'] ?? null),
            'commander_name' => self::text($data['commander_name'] ?? null),
            'commander_rank' => self::text($data['commander_rank'] ?? null),
            'personnel_strength' => isset($data['personnel_strength']) ? (int) $data['personnel_strength'] : null,
            'is_temporary' => $temporary,
            'active_from' => $data['active_from'] ?? null,
            'active_to' => $data['active_to'] ?? null,
            'status' => (string) $data['status'],
            'classification' => (string) $data['classification'],
            'notes' => self::text($data['notes'] ?? null),
            // Absent: unchanged.
            ...($this->has('parent') ? ['parent_formation_id' => $this->parent()?->id] : []),
        ];
    }

    private function validateParent(Closure $fail): void
    {
        if (! is_string($this->input('parent'))) {
            return;
        }

        $parent = $this->parent();
        $agency = $this->agency();
        $current = $this->formation();

        if ($parent === null) {
            $fail('Choose a parent formation you can see.');

            return;
        }

        if ($agency !== null && $parent->security_agency_id !== $agency->id) {
            $fail('The parent formation must belong to the same agency.');

            return;
        }

        if ($current !== null && self::isSelfOrDescendant($parent, $current)) {
            $fail('A formation cannot sit under itself or one of its own sub-formations.');
        }
    }

    /**
     * Whether $candidate is $formation or lies below it. Walks up from the
     * candidate; the database refuses cycles as well.
     */
    private static function isSelfOrDescendant(AgencyFormation $candidate, AgencyFormation $formation): bool
    {
        $seen = [];
        $id = $candidate->id;

        while ($id !== null && ! isset($seen[$id])) {
            if ($id === $formation->id) {
                return true;
            }

            $seen[$id] = true;
            // Ids only (no content), so the plain query builder is enough.
            $parent = DB::table('agency_formations')->where('id', $id)->value('parent_formation_id');
            $id = is_int($parent) ? $parent : null;
        }

        return false;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
