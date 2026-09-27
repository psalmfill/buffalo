<?php

namespace App\Http\Requests\Persons;

use App\Enums\Classification;
use App\Enums\Gender;
use App\Enums\PersonRoleType;
use App\Models\Community;
use App\Models\Lga;
use App\Models\Person;
use App\Models\User;
use App\Rules\PersonPhoto;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\LocalGovernment\CommunityRules;
use App\Support\Persons\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a person (/persons) or edit one (/persons/{person}).
 *
 * - The owning LGA is chosen on create (within the user's jurisdiction) and
 *   fixed afterwards; the community must belong to it.
 * - The classification must be one the user may assign.
 * - Roles are the checked boxes (never person of interest: POI module).
 * - NIN: exactly 11 digits; phone: a Nigerian number, stored normalised.
 * - A photo may be added on create (content-validated, metadata stripped).
 * - On create, possible duplicates must have been reviewed (confirm_new).
 *
 * Authorisation is in the controller.
 */
class PersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function person(): ?Person
    {
        $person = $this->route('person');

        return $person instanceof Person ? $person : null;
    }

    public function lga(): ?Lga
    {
        if ($this->person() !== null) {
            return $this->person()->lga;
        }

        $ulid = $this->input('lga');

        if (! is_string($ulid)) {
            return null;
        }

        /** @var User $user */
        $user = $this->user();

        return app(JurisdictionOptions::class)->lgaQuery($user)->active()->where('ulid', $ulid)->first();
    }

    public function community(): ?Community
    {
        $lga = $this->lga();

        return $lga === null ? null : app(CommunityRules::class)->communityOf($lga, $this->input('community'));
    }

    protected function prepareForValidation(): void
    {
        $nin = $this->input('nin');

        if (is_string($nin)) {
            $this->merge(['nin' => preg_replace('/\s+/', '', $nin)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->person() === null;

        return [
            'lga' => $creating ? ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() === null) {
                    $fail('Choose an LGA within your jurisdiction.');
                }
            }] : ['prohibited'],
            'community' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() !== null && $this->community() === null) {
                    $fail("The community must be a community of {$this->lga()->name} LGA.");
                }
            }],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:150'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:1900-01-01'],
            'approximate_age' => ['nullable', 'integer', 'min:0', 'max:130', 'prohibits:date_of_birth'],
            'nin' => ['nullable', 'string', 'regex:/^\d{11}$/'],
            'phone' => ['nullable', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && PhoneNumber::normalise($value) === null) {
                    $fail('Enter a Nigerian phone number, e.g. 0803 123 4567 or +234 803 123 4567.');
                }
            }],
            'address' => ['nullable', 'string', 'max:1000'],
            'occupation' => ['nullable', 'string', 'max:150'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $level = Classification::tryFrom((string) $value);
                /** @var User $user */
                $user = $this->user();

                if ($level !== null && ! app(ClassificationGate::class)->canAssign($user, $level)) {
                    $fail('You cannot classify a record above your own clearance.');
                }
            }],
            'is_deceased' => ['boolean'],
            'roles' => ['nullable', 'array', 'max:'.count(PersonRoleType::assignable())],
            'roles.*.role' => ['required', 'string', 'distinct', Rule::in(array_map(fn (PersonRoleType $role) => $role->value, PersonRoleType::assignable()))],
            'roles.*.notes' => ['nullable', 'string', 'max:1000'],
            'photo' => $creating ? ['nullable', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp', new PersonPhoto] : ['prohibited'],
            'confirm_new' => $creating ? ['boolean'] : ['prohibited'],
            'hit_justification' => $creating ? ['nullable', 'string', 'min:10', 'max:1000'] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nin.regex' => 'A NIN is exactly 11 digits.',
            'approximate_age.prohibits' => 'Give either a date of birth or an approximate age, not both.',
            'roles.*.role.in' => 'The person of interest role is managed in the persons of interest module.',
            'photo.mimetypes' => 'The photo must be a JPEG, PNG or WebP image.',
            'photo.max' => 'The photo may be at most 10 MB.',
            'lga.prohibited' => 'A person\'s LGA cannot be changed.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['nin' => 'NIN', 'date_of_birth' => 'date of birth'];
    }

    /**
     * Validated person values for SavePerson (phone normalised).
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->safe()->except(['lga', 'community', 'roles', 'photo', 'confirm_new', 'hit_justification']);

        if (isset($data['phone']) && is_string($data['phone'])) {
            $data['phone'] = PhoneNumber::normalise($data['phone']);
        }

        return $data;
    }

    /**
     * Checked roles: role value => notes.
     *
     * @return array<string, string|null>
     */
    public function roles(): array
    {
        $roles = [];

        foreach ($this->validated('roles') ?? [] as $role) {
            $roles[(string) $role['role']] = isset($role['notes']) ? (string) $role['notes'] : null;
        }

        return $roles;
    }
}
