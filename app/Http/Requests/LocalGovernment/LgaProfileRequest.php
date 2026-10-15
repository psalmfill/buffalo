<?php

namespace App\Http\Requests\LocalGovernment;

use App\Enums\Terrain;
use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An LGA's profile and editable basic facts. Authorisation is in the
 * controller (LgaPolicy::updateProfile).
 */
class LgaProfileRequest extends FormRequest
{
    public const PROFILE_FIELDS = ['history', 'economic_activities', 'terrain', 'major_roads', 'waterways', 'borders', 'general_notes'];

    public const BASIC_FIELDS = ['headquarters', 'area_km2', 'population_estimate'];

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
            'headquarters' => ['nullable', 'string', 'max:255'],
            'area_km2' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'population_estimate' => ['nullable', 'integer', 'min:0', 'max:100000000'],
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
     * @return array{headquarters?: string|null, area_km2?: numeric-string|float|int|null, population_estimate?: int|null}
     */
    public function basics(): array
    {
        /** @var array{headquarters?: string|null, area_km2?: numeric-string|float|int|null, population_estimate?: int|null} */
        return $this->safe()->only(self::BASIC_FIELDS);
    }
}
