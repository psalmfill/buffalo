<?php

namespace App\Http\Requests\Hierarchy;

use App\Actions\Hierarchy\Admin\HierarchyChange;
use App\Models\State;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A change in Administration › Hierarchy: StatePolicy::manageHierarchy
 * before validation, and a reason every time. The actions repeat the
 * import's rules (formats, uniqueness, parents) as the single source.
 */
class HierarchyChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageHierarchy', State::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:'.HierarchyChange::MAX_REASON],
            ...$this->fields(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fields(): array
    {
        return [];
    }

    public function reason(): string
    {
        return $this->string('reason')->toString();
    }

    public function text(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) ? $value : null;
    }
}
