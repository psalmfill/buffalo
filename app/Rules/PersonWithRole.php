<?php

namespace App\Rules;

use App\Enums\PersonRoleType;
use App\Models\Person;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/**
 * The value is the ULID of a person the user can see (both scopes and the
 * policy) who holds $role actively. $keep (the record's current person)
 * stays valid even if their role has since been deactivated, so existing
 * records remain editable.
 */
final class PersonWithRole implements ValidationRule
{
    public function __construct(
        private readonly PersonRoleType $role,
        private readonly ?string $keep = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = Auth::user();
        $person = is_string($value) ? Person::query()->where('ulid', $value)->first() : null;

        if (! $user instanceof User || $person === null || ! $user->can('link', $person)) {
            $fail('Choose a person you can view.');

            return;
        }

        if ($value !== $this->keep && ! $person->holdsRole($this->role)) {
            $fail('The person must hold the '.strtolower($this->role->label()).' role.');
        }
    }
}
