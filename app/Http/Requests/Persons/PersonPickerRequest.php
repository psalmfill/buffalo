<?php

namespace App\Http\Requests\Persons;

use App\Enums\PersonRoleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The person picker (POST: names stay out of URLs and logs). Optionally
 * only persons holding an active role (e.g. traditional rulers).
 */
class PersonPickerRequest extends FormRequest
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
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'role' => ['nullable', Rule::enum(PersonRoleType::class)],
            'except' => ['nullable', 'string', 'size:26'],
        ];
    }
}
