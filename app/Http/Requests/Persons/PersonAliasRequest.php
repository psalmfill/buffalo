<?php

namespace App\Http\Requests\Persons;

use App\Models\Person;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * A new alias for a person; not a duplicate of one of their aliases
 * (compared like search: case- and accent-insensitive). Authorisation is
 * in the controller.
 */
class PersonAliasRequest extends FormRequest
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
        /** @var Person $person */
        $person = $this->route('person');

        return [
            'alias' => ['required', 'string', 'max:150', function (string $attribute, mixed $value, Closure $fail) use ($person): void {
                if (! is_string($value) || trim($value) === '') {
                    return;
                }

                $exists = DB::table('person_aliases')
                    ->where('person_id', $person->id)
                    ->whereNull('deleted_at')
                    ->whereRaw('search_alias = buffalo_search_text(?)', [$value])
                    ->exists();

                if ($exists) {
                    $fail('This person already has this alias.');
                }
            }],
        ];
    }
}
