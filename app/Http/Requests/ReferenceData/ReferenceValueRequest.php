<?php

namespace App\Http\Requests\ReferenceData;

use App\Enums\AgencyType;
use App\Enums\IncidentCategory;
use App\Enums\Severity;
use App\Models\OffenceType;
use App\Models\ReferenceModel;
use App\Models\Religion;
use App\Models\SecurityAgency;
use App\Models\State;
use App\Models\ThreatType;
use App\Rules\ActiveOption;
use App\Support\DynamicFields\SchemaValidator;
use App\Support\ReferenceData\ReferenceTable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or edit a reference value of any table (/reference-data/{table}).
 * Authorisation is in the controller (ReferenceDataPolicy).
 *
 * `code` is set on create and fixed afterwards; a denomination's religion
 * too (its code is unique within the religion). Selections (religion,
 * state) must be active, except the value a record already has.
 */
class ReferenceValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function table(): ReferenceTable
    {
        $table = $this->route('table');

        return $table instanceof ReferenceTable ? $table : ReferenceTable::from((string) $table);
    }

    /**
     * The value being edited, or null when creating.
     */
    public function target(): ?ReferenceModel
    {
        $ulid = $this->route('value');

        /** @var ?ReferenceModel */
        return is_string($ulid) ? $this->table()->query()->where('ulid', $ulid)->first() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $table = $this->table();
        $target = $this->target();
        $creating = $target === null;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'code' => $creating
                ? ['required', 'string', 'max:64', 'regex:'.SchemaValidator::KEY_PATTERN, $this->uniqueCode($table)]
                : ['prohibited'],
        ];

        return [...$rules, ...match ($table) {
            ReferenceTable::IncidentTypes => [
                'category' => ['required', Rule::enum(IncidentCategory::class)],
                'default_severity' => ['required', Rule::enum(Severity::class)],
            ],
            ReferenceTable::ThreatTypes => [
                'category' => ['required', Rule::enum(IncidentCategory::class)],
            ],
            ReferenceTable::SecurityAgencies => [
                'short_name' => ['required', 'string', 'max:30'],
                'agency_type' => ['required', Rule::enum(AgencyType::class)],
                'state' => [
                    Rule::requiredIf(fn (): bool => $this->input('agency_type') === AgencyType::State->value),
                    Rule::prohibitedIf(fn (): bool => $this->input('agency_type') !== AgencyType::State->value),
                    'nullable',
                    new ActiveOption(State::class, $target instanceof SecurityAgency ? $target->state?->ulid : null),
                ],
            ],
            ReferenceTable::Denominations => [
                'religion' => $creating ? ['required', new ActiveOption(Religion::class)] : ['prohibited'],
            ],
            ReferenceTable::OffenceTypes => [
                'threat_type' => ['nullable', new ActiveOption(ThreatType::class, $target instanceof OffenceType ? $target->threatType?->ulid : null)],
            ],
            ReferenceTable::Religions, ReferenceTable::PropertyTypes, ReferenceTable::OrganizationSectors, ReferenceTable::PersonRoleTypes => [],
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code must be lowercase snake_case: letters, digits and underscores, starting with a letter.',
            'code.prohibited' => 'The code cannot be changed once created.',
            'religion.prohibited' => 'The religion of a denomination cannot be changed.',
            'state.required' => 'A state outfit must belong to a state.',
            'state.prohibited' => 'Only state outfits belong to a state.',
        ];
    }

    /**
     * Validated input as column values (ULIDs resolved to ids).
     *
     * @return array<string, mixed>
     */
    public function columnValues(): array
    {
        $data = $this->validated();

        $values = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        if (isset($data['code'])) {
            $values['code'] = $data['code'];
        }

        foreach (['category', 'default_severity', 'short_name', 'agency_type'] as $column) {
            if (array_key_exists($column, $data)) {
                $values[$column] = $data[$column];
            }
        }

        if ($this->table() === ReferenceTable::SecurityAgencies) {
            $values['state_id'] = isset($data['state']) ? State::query()->where('ulid', $data['state'])->value('id') : null;
        }

        if ($this->table() === ReferenceTable::OffenceTypes) {
            $values['threat_type_id'] = isset($data['threat_type']) ? ThreatType::query()->where('ulid', $data['threat_type'])->value('id') : null;
        }

        if (isset($data['religion'])) {
            $values['religion_id'] = Religion::query()->where('ulid', $data['religion'])->value('id');
        }

        return $values;
    }

    /**
     * Codes are unique per table; a denomination's code within its religion.
     */
    private function uniqueCode(ReferenceTable $table): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($table): void {
            $query = $table->query()->where('code', $value);

            if ($table === ReferenceTable::Denominations) {
                $query->whereHas('religion', fn ($religion) => $religion->where('ulid', $this->input('religion')));
            }

            if ($query->exists()) {
                $fail('This code is already used.');
            }
        };
    }
}
