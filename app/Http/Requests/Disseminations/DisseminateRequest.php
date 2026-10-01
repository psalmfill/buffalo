<?php

namespace App\Http\Requests\Disseminations;

use App\Enums\DisseminationTargetLevel;
use App\Models\Lga;
use App\Models\State;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Targets and reason for a dissemination, an onward dissemination or a
 * dissemination request (Phase 7A). Targets are `{level, ulid}`: a state
 * ULID for `state`, an LGA ULID for `lga`. Whether each target is allowed
 * is decided by the action (DisseminateRecord); authorisation by the
 * controller.
 */
class DisseminateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'targets' => ['required', 'array', 'min:1', 'max:50'],
            'targets.*.level' => ['required', Rule::enum(DisseminationTargetLevel::class)],
            'targets.*.ulid' => ['required', 'string', 'size:26'],
            'reason' => ['required', 'string', 'min:'.(int) config('buffalo.disseminations.reason_min_length'), 'max:2000'],
            'request' => ['nullable', 'string', 'size:26'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'targets.required' => 'Choose at least one state or LGA.',
            'reason.min' => 'Give a reason of at least :min characters.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach ((array) $this->input('targets') as $target) {
                    $exists = ($target['level'] ?? null) === DisseminationTargetLevel::State->value
                        ? State::query()->where('ulid', $target['ulid'] ?? '')->exists()
                        : Lga::query()->where('ulid', $target['ulid'] ?? '')->exists();

                    if (! $exists) {
                        $validator->errors()->add('targets', 'Choose valid states and LGAs.');

                        return;
                    }
                }
            },
        ];
    }

    /**
     * @return list<array{level: DisseminationTargetLevel, state: State, lga: Lga|null}>
     */
    public function targets(): array
    {
        $targets = [];
        $seen = [];

        foreach ((array) $this->validated('targets') as $target) {
            $key = $target['level'].':'.$target['ulid'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if ($target['level'] === DisseminationTargetLevel::State->value) {
                $targets[] = ['level' => DisseminationTargetLevel::State, 'state' => State::query()->where('ulid', $target['ulid'])->firstOrFail(), 'lga' => null];
            } else {
                $lga = Lga::query()->where('ulid', $target['ulid'])->with('state')->firstOrFail();
                $targets[] = ['level' => DisseminationTargetLevel::Lga, 'state' => $lga->state, 'lga' => $lga];
            }
        }

        return $targets;
    }

    /**
     * @return list<array{level: string, ulid: string}>
     */
    public function rawTargets(): array
    {
        return array_values(array_map(fn (array $t) => ['level' => (string) $t['level'], 'ulid' => (string) $t['ulid']], (array) $this->validated('targets')));
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
