<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompleteAccountSetup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AccountSetupRequest;
use App\Models\AccountSetupToken;
use App\Models\User;
use App\Support\Auth\PasswordRules;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /account/setup/{token}: a new user chooses their password through a
 * one-time link, is logged in, and goes straight to two-factor enrolment.
 *
 * Invalid, expired and used links (and links for inactive accounts) all get
 * the same generic page, so the link reveals nothing.
 */
class AccountSetupController extends Controller
{
    public function show(string $token): Response
    {
        $user = $this->userForToken($token);

        if ($user === null) {
            return $this->invalid();
        }

        return Inertia::render('auth/account-setup', [
            'token' => $token,
            'name' => $user->name,
            'email' => $user->email,
            'passwordRules' => PasswordRules::passwordRulesAttribute(),
            'passwordRequirements' => PasswordRules::descriptions(),
        ]);
    }

    public function store(AccountSetupRequest $request, string $token, CompleteAccountSetup $complete): RedirectResponse|Response
    {
        $user = $complete($token, $request->string('password')->toString());

        if ($user === null) {
            return $this->invalid();
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Phase 1C.4 sends new users straight to two-factor enrolment.
        return redirect()->route('dashboard');
    }

    private function invalid(): Response
    {
        return Inertia::render('auth/account-setup-invalid');
    }

    /**
     * The user a usable token belongs to, or null. Looked up through the
     * authentication user provider: this is a guest request, in which the
     * jurisdiction-scoped User model returns nothing.
     */
    private function userForToken(string $token): ?User
    {
        $record = AccountSetupToken::query()->where('token_hash', AccountSetupToken::hash($token))->usable()->first();

        if ($record === null) {
            return null;
        }

        $user = $this->provider()->retrieveById($record->user_id);

        return $user instanceof User && ! $user->isAccessBlocked() ? $user : null;
    }

    private function provider(): UserProvider
    {
        return Auth::guard()->getProvider();
    }
}
