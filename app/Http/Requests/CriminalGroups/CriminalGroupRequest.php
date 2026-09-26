<?php

namespace App\Http\Requests\CriminalGroups;

use App\Enums\Classification;
use App\Enums\CriminalGroupStatus;
use App\Enums\CriminalGroupType;
use App\Models\CriminalGroup;
use App\Models\Lga;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionOptions;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a criminal group (home LGA within the user's jurisdiction, fixed
 * afterwards) or edit one. The classification must be one the user may
 * assign. Authorisation is in the controller.
 */
class CriminalGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function group(): ?CriminalGroup
    {
        $group = $this->route('group');

        return $group instanceof CriminalGroup ? $group : null;
    }

    public function lga(): ?Lga
    {
        $ulid = $this->input('lga');

        if (! is_string($ulid)) {
            return null;
        }

        /** @var User $user */
        $user = $this->user();

        return app(JurisdictionOptions::class)->lgaQuery($user)->active()->where('ulid', $ulid)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lga' => $this->group() === null ? ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() === null) {
                    $fail('Choose a home LGA within your jurisdiction.');
                }
            }] : ['prohibited'],
            'name' => ['required', 'string', 'max:200'],
            'other_names' => ['present', 'array', 'max:20'],
            'other_names.*' => ['nullable', 'string', 'max:200', 'distinct:ignore_case'],
            'type' => ['required', Rule::enum(CriminalGroupType::class)],
            'description' => ['required', 'string', 'max:20000'],
            'estimated_size' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'status' => ['required', Rule::enum(CriminalGroupStatus::class)],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $level = Classification::tryFrom((string) $value);
                /** @var User $user */
                $user = $this->user();

                if ($level !== null && ! app(ClassificationGate::class)->canAssign($user, $level)) {
                    $fail('You cannot classify a record above your own clearance.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['lga.prohibited' => 'A group\'s home LGA cannot be changed.'];
    }
}
