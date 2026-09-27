<?php

namespace App\Http\Requests\Persons;

use App\Enums\AssociationRelationship;
use App\Models\Person;
use App\Models\PersonAssociation;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Link the person in the URL with another person. The other person must be
 * one the user can currently see (bound through both scopes and the
 * policy); no self-link; one link per pair. Authorisation of the person in
 * the URL is in the controller.
 */
class PersonAssociationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function person(): Person
    {
        /** @var Person */
        return $this->route('person');
    }

    /** The other person, when visible to the user. */
    public function other(): ?Person
    {
        $ulid = $this->input('person');

        if (! is_string($ulid)) {
            return null;
        }

        /** @var User $user */
        $user = $this->user();
        $other = Person::query()->where('ulid', $ulid)->first();

        // In the user's own jurisdiction: never a person seen only under a grant.
        return $other !== null && $user->can('link', $other) ? $other : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'person' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $other = $this->other();

                if ($other === null) {
                    $fail('Choose a person you can view.');

                    return;
                }

                if ($other->is($this->person())) {
                    $fail('A person cannot be associated with themselves.');

                    return;
                }

                $linked = PersonAssociation::query()
                    ->where(fn ($q) => $q->where('person_id', $this->person()->id)->where('associated_person_id', $other->id))
                    ->orWhere(fn ($q) => $q->where('person_id', $other->id)->where('associated_person_id', $this->person()->id))
                    ->exists();

                if ($linked) {
                    $fail('These two persons are already associated.');
                }
            }],
            'relationship' => ['required', Rule::enum(AssociationRelationship::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
