<?php

namespace App\Http\Requests\System;

use App\Models\State;
use Illuminate\Foundation\Http\FormRequest;

class SetStateLiveRequest extends FormRequest
{
    /** StatePolicy::manageRollout, before validation (super admins only). */
    public function authorize(): bool
    {
        return $this->user()?->can('manageRollout', State::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'live' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
