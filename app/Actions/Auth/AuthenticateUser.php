<?php

namespace App\Actions\Auth;

use App\Enums\LoginEvent;
use App\Models\User;
use App\Support\Auth\LoginHistoryRecorder;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

/**
 * The password step of every login (Fortify::authenticateUsing).
 *
 * Returns the user only when the credentials are right AND the account may
 * sign in. Unknown email, wrong password, locked, deactivated or deleted
 * account all return null, so the login form always shows the same generic
 * message and reveals nothing about the account.
 *
 * Remember-me is not supported: it would outlive the idle and absolute
 * session limits.
 */
final class AuthenticateUser
{
    public function __construct(
        private readonly RecordFailedLogin $recordFailure,
        private readonly LoginHistoryRecorder $history,
    ) {}

    public function __invoke(Request $request): ?User
    {
        $request->request->remove('remember');

        $email = strtolower(trim((string) $request->input(Fortify::username())));
        $password = (string) $request->input('password');

        $user = $email === '' ? null : $this->provider()->retrieveByCredentials(['email' => $email]);

        if (! $user instanceof User) {
            // Same work as a real check, so timing does not reveal accounts.
            Hash::check($password, '$2y$12$C6UzMDM.H6dfI/f/IKcEeO5c3S0Q5B.7p9Z7Yx7J2fW8Zc6oY8C9W');
            $this->history->record(LoginEvent::LoginFailed, emailAttempted: $email);

            return null;
        }

        if ($user->isAccessBlocked()) {
            $this->history->record(LoginEvent::LoginFailed, $user, $email);

            return null;
        }

        if (! $this->provider()->validateCredentials($user, ['password' => $password])) {
            $this->history->record(LoginEvent::LoginFailed, $user, $email);
            ($this->recordFailure)($user);

            return null;
        }

        return $user;
    }

    private function provider(): UserProvider
    {
        return Auth::guard()->getProvider();
    }
}
