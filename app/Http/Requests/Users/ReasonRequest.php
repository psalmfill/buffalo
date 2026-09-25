<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A required reason for deactivating or reactivating an account; the
 * ability checked is the route's action (deactivate / reactivate).
 */
class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');
        $ability = $this->routeIs('users.deactivate') ? 'deactivate' : 'reactivate';

        return $target instanceof User && ($this->user()?->can($ability, $target) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
