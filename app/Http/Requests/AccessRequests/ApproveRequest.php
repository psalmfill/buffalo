<?php

namespace App\Http\Requests\AccessRequests;

use App\Enums\GrantScope;
use App\Models\RecordAccessRequest;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Approve an access request, optionally shortening the duration or
 * narrowing full to profile, never widening.
 */
class ApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function accessRequest(): RecordAccessRequest
    {
        /** @var RecordAccessRequest */
        return $this->route('accessRequest');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $request = $this->accessRequest();

        return [
            'granted_days' => ['required', 'integer', 'min:1', 'max:'.$request->requested_days],
            'granted_scope' => ['required', Rule::enum(GrantScope::class), function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                $scope = GrantScope::tryFrom((string) $value);

                if ($scope !== null && ! $request->scope->includes($scope)) {
                    $fail('The scope may only be narrowed, never widened.');
                }
            }],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['granted_days.max' => 'The duration may only be shortened (at most :max days).'];
    }
}
