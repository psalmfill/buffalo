<?php

namespace App\Http\Requests\Organizations;

use App\Models\OrganizationContact;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\User;
use App\Support\Persons\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add or edit a contact at a site (Phase 4B). The phone must be a Nigerian
 * number (normalised); the email is lower-cased. At most one primary
 * contact per site. A linked person must be one the user may LINK (access
 * rule 9); `person` absent on edit = unchanged (a person the user cannot
 * see is kept). Authorisation is in the controller.
 */
class OrganizationContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function contact(): ?OrganizationContact
    {
        $contact = $this->route('contact');

        return $contact instanceof OrganizationContact ? $contact : null;
    }

    public function site(): OrganizationSite
    {
        $contact = $this->contact();
        $site = $contact !== null ? $contact->site : $this->route('site');

        abort_unless($site instanceof OrganizationSite, 404);

        return $site;
    }

    public function person(): ?Person
    {
        $ulid = $this->input('person');

        return is_string($ulid) ? Person::query()->where('ulid', $ulid)->first() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'position' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && PhoneNumber::normalise($value) === null) {
                    $fail('Enter a Nigerian phone number, e.g. 0803 123 4567.');
                }
            }],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'is_management' => ['required', 'boolean'],
            'is_security_contact' => ['required', 'boolean'],
            'is_primary' => ['required', 'boolean', function (string $attribute, mixed $value, Closure $fail): void {
                if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    return;
                }

                $taken = OrganizationContact::query()
                    ->where('organization_site_id', $this->site()->id)
                    ->where('is_primary', true)
                    ->when($this->contact(), fn ($q, OrganizationContact $contact) => $q->whereKeyNot($contact->id))
                    ->exists();

                if ($taken) {
                    $fail('This site already has a primary contact.');
                }
            }],
            'person' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $person = $this->person();
                /** @var User $user */
                $user = $this->user();

                if ($person === null || ! $user->can('link', $person)) {
                    $fail('Choose a person you may link to.');
                }
            }],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->validated();
        $text = fn (string $key): ?string => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        return [
            'name' => trim((string) $data['name']),
            'position' => trim((string) $data['position']),
            'phone' => $text('phone'),
            'email' => $text('email'),
            'is_management' => (bool) $data['is_management'],
            'is_security_contact' => (bool) $data['is_security_contact'],
            'is_primary' => (bool) $data['is_primary'],
            'notes' => $text('notes'),
            // Absent: unchanged.
            ...($this->has('person') ? ['person_id' => $this->person()?->id] : []),
        ];
    }
}
