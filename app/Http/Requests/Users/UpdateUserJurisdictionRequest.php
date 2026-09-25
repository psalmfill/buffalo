<?php

namespace App\Http\Requests\Users;

use App\Models\User;

class UpdateUserJurisdictionRequest extends UserJurisdictionRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && ($this->user()?->can('changeRole', $target) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->jurisdictionRules();
    }
}
