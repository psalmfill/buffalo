<?php

namespace App\Http\Requests\LocalGovernment;

use App\Enums\Terrain;
use App\Models\State;
use App\Rules\RichTextRule;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A state's profile and its capital (Phase 11F). Authorised here, before
 * validation (StatePolicy::updateProfile: 403, or 404 outside reach), so
 * a user without rights never learns about validation rules; the
 * controller authorises again.
 */
class StateProfileRequest extends FormRequest
{
    public const PROFILE_FIELDS = LgaProfileRequest::PROFILE_FIELDS;

    public function authorize(): Response
    {
        $state = $this->route('state');

        return $state instanceof State ? Gate::inspect('updateProfile', $state) : Response::denyAsNotFound();
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
