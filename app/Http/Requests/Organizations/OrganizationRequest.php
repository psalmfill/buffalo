<?php

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationSector;
use App\Rules\ActiveOption;
use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or edit a directory entry (Phase 4B). Public, non-sensitive
 * identity data only. The RC number is unique among entries (normalised to
 * upper case, spaces removed). Authorisation is in the controller.
 */
class OrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function organization(): ?Organization
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization ? $organization : null;
    }

    protected function prepareForValidation(): void
    {
        $rc = $this->input('rc_number');

        if (is_string($rc)) {
            $this->merge(['rc_number' => self::normaliseRc($rc)]);
        }
    }

    public static function normaliseRc(string $rc): ?string
    {
        $clean = strtoupper(preg_replace('/[\s\-]/', '', $rc) ?? '');

        return $clean === '' ? null : $clean;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->organization();

        return [
            'name' => ['required', 'string', 'max:255'],
            'other_names' => ['present', 'array', 'max:20'],
            'other_names.*' => ['nullable', 'string', 'max:255', 'distinct:ignore_case'],
            'type' => ['required', Rule::enum(OrganizationType::class)],
            'sector' => ['required', 'string', new ActiveOption(OrganizationSector::class, $current?->sector->ulid)],
            'rc_number' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('organizations', 'rc_number')->whereNull('deleted_at')->ignore($current?->id)],
            'website' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', new RichTextRule(5000)],
            'status' => ['required', Rule::enum(OrganizationStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rc_number.unique' => 'Another organization already has this RC number.',
            'rc_number.regex' => 'An RC number has letters and digits only.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->validated();

        return [
            'name' => trim((string) $data['name']),
            'other_names' => array_values(array_filter(array_map(
                fn (mixed $name): ?string => is_string($name) && trim($name) !== '' ? trim($name) : null,
                (array) $data['other_names'],
            ))),
            'type' => (string) $data['type'],
            'organization_sector_id' => (int) OrganizationSector::query()->where('ulid', $data['sector'])->value('id'),
            'rc_number' => $data['rc_number'] ?? null,
            'website' => isset($data['website']) && trim((string) $data['website']) !== '' ? trim((string) $data['website']) : null,
            'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
            'status' => (string) $data['status'],
        ];
    }
}
