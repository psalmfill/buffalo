<?php

namespace App\Http\Requests\Auth;

use App\Support\Auth\PasswordRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AccountSetupRequest extends FormRequest
{
    /**
     * Policy rules; the reuse check needs the user and runs in
     * CompleteAccountSetup once the token is resolved.
     *
     * @return array<string, list<string|Password|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'password' => PasswordRules::rules(),
        ];
    }
}
