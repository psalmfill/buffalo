<?php

namespace App\Http\Requests\LocalGovernment;

use App\Enums\Terrain;
use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A state's profile and its capital (Phase 11F). Authorisation is in the
 * controller (StatePolicy::updateProfile).
 */
class StateProfileRequest extends FormRequest
{
    public const PROFILE_FIELDS = LgaProfileRequest::PROFILE_FIELDS;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $text = ['nullable', new RichTextRule(20000)];

        return [
            'history' => $text,
            'economic_activities' => $text,
            'terrain' => ['present', 'array'],
            'terrain.*' => ['distinct', Rule::enum(Terrain::class)],
            'major_roads' => $text,
            'waterways' => $text,
            'borders' => $text,
            'general_notes' => $text,
            'capital' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        $values = $this->safe()->only(self::PROFILE_FIELDS);
        // In the order of Terrain::cases(), without duplicates.
        $values['terrain'] = array_values(array_intersect(array_column(Terrain::cases(), 'value'), $values['terrain'] ?? []));

        return $values;
    }

    /**
     * @return array{capital?: string|null}
     */
    public function facts(): array
    {
        /** @var array{capital?: string|null} */
        return $this->safe()->only(['capital']);
    }
}
