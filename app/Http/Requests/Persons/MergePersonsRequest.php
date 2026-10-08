<?php

namespace App\Http\Requests\Persons;

use App\Support\Persons\Merge\MergeChoices;
use App\Support\Persons\Merge\MergeFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Executing a merge (Phase 10A.5): the pair and the executor's choices. */
class MergePersonsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorises both persons
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $side = Rule::in(['survivor', 'source']);

        return [
            'survivor' => ['required', 'string', 'size:26'],
            'source' => ['required', 'string', 'size:26', 'different:survivor'],
            'request' => ['nullable', 'string', 'size:26'],
            'fields' => ['array'],
            'fields.*' => [$side],
            'place' => ['required', $side],
            'photo' => ['nullable', $side],
            'poi_status' => ['nullable', $side],
            'confirm_dob' => ['boolean'],
            'confirm_deceased' => ['boolean'],
        ];
    }

    public function choices(): MergeChoices
    {
        /** @var array<string, 'survivor'|'source'> $fields */
        $fields = array_intersect_key((array) $this->validated('fields', []), MergeFields::FIELDS);

        return new MergeChoices(
            fields: $fields,
            place: $this->validated('place') === 'source' ? 'source' : 'survivor',
            photo: in_array($this->validated('photo'), ['survivor', 'source'], true) ? $this->validated('photo') : null,
            poiStatus: $this->validated('poi_status') === 'source' ? 'source' : 'survivor',
            confirmDob: $this->boolean('confirm_dob'),
            confirmDeceased: $this->boolean('confirm_deceased'),
        );
    }
}
