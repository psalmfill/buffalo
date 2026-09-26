<?php

namespace App\Http\Requests\CriminalGroups;

use App\Models\CriminalGroup;
use App\Models\CriminalGroupArea;
use App\Models\Lga;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add an area of operation: any active LGA in any state (areas describe
 * the group and grant no visibility), once per group.
 */
class GroupAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function lga(): ?Lga
    {
        $ulid = $this->input('lga');

        return is_string($ulid) ? Lga::query()->active()->where('ulid', $ulid)->first() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var CriminalGroup $group */
        $group = $this->route('group');

        return [
            'lga' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($group): void {
                $lga = $this->lga();

                if ($lga === null) {
                    $fail('Choose an LGA.');
                } elseif (CriminalGroupArea::query()->where('criminal_group_id', $group->id)->where('lga_id', $lga->id)->exists()) {
                    $fail('This LGA is already an area of operation.');
                }
            }],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
