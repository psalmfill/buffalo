<?php

namespace App\Http\Requests\AccessRequests;

use App\Enums\Role;
use App\Models\ApprovalDelegation;
use App\Models\User;
use App\Support\Jurisdiction\AccessRequestApprovers;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Delegate the approval of access requests to a current Deputy State
 * Director of the director's own state, who has no delegation already
 * (at most one active per delegate). Authorisation (the current State
 * Director) is in the controller.
 */
class DelegationRequest extends FormRequest
{
    /**
     * Authorised here, before validation: the rules are relative to the
     * director's own state and would otherwise answer non-directors with
     * validation messages about deputies.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', ApprovalDelegation::class) ?? false;
    }

    public function deputy(): ?User
    {
        /** @var User $director */
        $director = $this->user();
        $ulid = $this->input('delegate');

        if (! is_string($ulid)) {
            return null;
        }

        $deputy = User::query()->where('ulid', $ulid)->first();

        return $deputy !== null
            && $deputy->getRole() === Role::DeputyStateDirector
            && $deputy->state_id === $director->state_id
            && ! $deputy->isAccessBlocked()
                ? $deputy
                : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'delegate' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $deputy = $this->deputy();

                if ($deputy === null) {
                    $fail('Choose a current Deputy State Director of your state.');
                } elseif (app(AccessRequestApprovers::class)->hasOpenDelegation($deputy)) {
                    $fail('This deputy already holds a delegation. Revoke it first.');
                }
            }],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('starts_at'));
    }

    public function endsAt(): ?CarbonImmutable
    {
        $value = $this->validated('ends_at');

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
