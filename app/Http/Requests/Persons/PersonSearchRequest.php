<?php

namespace App\Http\Requests\Persons;

use App\Enums\Classification;
use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The persons search (POST, so names, NINs and phone numbers never appear
 * in URLs, access logs or browser history). State and LGA filters are
 * checked against the user's jurisdiction by the controller.
 */
class PersonSearchRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::exists('person_role_types', 'code')],
            'state' => ['nullable', 'string', 'size:26'],
            'zone' => ['nullable', 'string', 'size:26'],
            'lga' => ['nullable', 'string', 'size:26'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'classification' => ['nullable', Rule::enum(Classification::class)],
            // Only used to check other jurisdictions by name (Phase 4A.0).
            'dob' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array{q: string, role: string, state: string, zone: string, lga: string, gender: string, classification: string, dob: string}
     */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'q' => trim((string) ($data['q'] ?? '')),
            'role' => (string) ($data['role'] ?? ''),
            'state' => (string) ($data['state'] ?? ''),
            'zone' => (string) ($data['zone'] ?? ''),
            'lga' => (string) ($data['lga'] ?? ''),
            'gender' => (string) ($data['gender'] ?? ''),
            'classification' => (string) ($data['classification'] ?? ''),
            'dob' => (string) ($data['dob'] ?? ''),
        ];
    }
}
