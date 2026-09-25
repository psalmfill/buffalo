<?php

namespace App\Actions\Auth;

use App\Enums\LoginEvent;
use App\Models\AccountSetupToken;
use App\Models\User;
use App\Support\Auth\LoginHistoryRecorder;
use App\Support\Auth\PasswordRules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Consumes a setup token and sets the user's first password. Returns the
 * user, or null when the token is invalid, expired, used or belongs to an
 * inactive account. The token row is locked, so a link can only be used
 * once even under concurrent requests.
 */
final class CompleteAccountSetup
{
    public function __construct(
        private readonly SetUserPassword $setPassword,
        private readonly LoginHistoryRecorder $history,
    ) {}

    public function __invoke(string $token, string $password): ?User
    {
        return DB::transaction(function () use ($token, $password): ?User {
            $record = AccountSetupToken::query()
                ->where('token_hash', AccountSetupToken::hash($token))
                ->lockForUpdate()
                ->first();

            if ($record === null || ! $record->isUsable()) {
                return null;
            }

            // Guest request: resolve through the unscoped auth provider.
            $user = Auth::guard()->getProvider()->retrieveById($record->user_id);

            if (! $user instanceof User || $user->isAccessBlocked()) {
                return null;
            }

            Validator::make(
                ['password' => $password, 'password_confirmation' => $password],
                ['password' => PasswordRules::rules($user)],
            )->validate();

            ($this->setPassword)($user, $password);
            $record->forceFill(['used_at' => now()])->save();
            $this->history->record(LoginEvent::SetupCompleted, $user);

            return $user;
        });
    }
}
