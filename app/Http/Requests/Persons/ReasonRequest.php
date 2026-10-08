<?php

namespace App\Http\Requests\Persons;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A reason: "Not a duplicate" for a pair of persons (Phase 10A.3), or the
 * note declining a merge request (reason only).
 */
class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorises
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->routeIs('persons.merge-requests.decline')
            ? ['reason' => ['required', 'string', 'min:10', 'max:1000']]
            : [
                'a' => ['required', 'string', 'size:26'],
                'b' => ['required', 'string', 'size:26', 'different:a'],
                'reason' => ['required', 'string', 'min:10', 'max:1000'],
            ];
    }
}
