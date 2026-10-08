<?php

namespace App\Http\Requests\Persons;

use App\Actions\Persons\UndoPersonMerge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Undoing a merge (Phase 10A.6): a reason, and the edits to keep. */
class UndoPersonMergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorises the merge
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'keep' => ['array'],
            'keep.*' => [Rule::in(array_keys(UndoPersonMerge::undoFields()))],
        ];
    }

    /** @return list<string> */
    public function keep(): array
    {
        return array_values(array_map('strval', (array) $this->validated('keep', [])));
    }
}
