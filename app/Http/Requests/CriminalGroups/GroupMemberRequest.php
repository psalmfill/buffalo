<?php

namespace App\Http\Requests\CriminalGroups;

use App\Enums\MembershipStatus;
use App\Models\CriminalGroup;
use App\Models\CriminalGroupMember;
use App\Models\Person;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a member (a person the user can see, not already a member) or edit
 * a membership (the person is fixed). Authorisation is in the controller.
 */
class GroupMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function member(): ?CriminalGroupMember
    {
        $member = $this->route('member');

        return $member instanceof CriminalGroupMember ? $member : null;
    }

    public function person(): ?Person
    {
        $ulid = $this->input('person');
        /** @var User $user */
        $user = $this->user();
        $person = is_string($ulid) ? Person::query()->where('ulid', $ulid)->first() : null;

        // In the user's own jurisdiction: never a person seen only under a grant.
        return $person !== null && $user->can('link', $person) ? $person : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var CriminalGroup $group */
        $group = $this->route('group');

        return [
            'person' => $this->member() === null ? ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($group): void {
                $person = $this->person();

                if ($person === null) {
                    $fail('Choose a person you can view.');
                } elseif (CriminalGroupMember::query()->where('criminal_group_id', $group->id)->where('person_id', $person->id)->exists()) {
                    $fail('This person is already a member.');
                }
            }] : ['prohibited'],
            'role_in_group' => ['nullable', 'string', 'max:100'],
            'membership_status' => ['required', Rule::enum(MembershipStatus::class)],
            'since' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array{role_in_group: string|null, membership_status: string, since: string|null, notes: string|null}
     */
    public function values(): array
    {
        $data = $this->validated();

        return [
            'role_in_group' => $data['role_in_group'] ?? null,
            'membership_status' => (string) $data['membership_status'],
            'since' => $data['since'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
