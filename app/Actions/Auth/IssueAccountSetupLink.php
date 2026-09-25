<?php

namespace App\Actions\Auth;

use App\Models\AccountSetupToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issues a one-time account setup link, valid for 72 hours, and returns the
 * plain URL. Any earlier unused link for the user stops working. The URL is
 * returned once and cannot be retrieved again (only its hash is stored):
 * the administrator either emails it (SendAccountSetupLink) or hands it
 * over by another route.
 *
 * Authorisation is the caller's job (Phase 1D: UserPolicy).
 */
final class IssueAccountSetupLink
{
    public function __invoke(User $user, ?User $actor = null): string
    {
        if ($user->isAccessBlocked()) {
            throw ValidationException::withMessages(['user' => 'Setup links can only be issued to active accounts.']);
        }

        $token = Str::random(64);

        DB::transaction(function () use ($user, $actor, $token): void {
            AccountSetupToken::query()
                ->where('user_id', $user->id)
                ->usable()
                ->update(['expires_at' => now()]);

            AccountSetupToken::query()->create([
                'user_id' => $user->id,
                'token_hash' => AccountSetupToken::hash($token),
                'expires_at' => now()->addHours(AccountSetupToken::VALID_HOURS),
                'created_by' => $actor?->id,
            ]);
        });

        return route('account-setup.show', ['token' => $token]);
    }
}
