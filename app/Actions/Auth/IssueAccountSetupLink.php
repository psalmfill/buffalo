<?php

namespace App\Actions\Auth;

use App\Enums\SetupLinkDelivery;
use App\Models\AccountSetupToken;
use App\Models\User;
use App\Support\Audit\AuditLog;
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
    public function __invoke(User $user, ?User $actor = null, SetupLinkDelivery $delivery = SetupLinkDelivery::Manual): string
    {
        if ($user->isAccessBlocked()) {
            throw ValidationException::withMessages(['user' => 'Setup links can only be issued to active accounts.']);
        }

        $token = Str::random(64);

        DB::transaction(function () use ($user, $actor, $token, $delivery): void {
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

            // The delivery method only: never the link or token.
            AuditLog::event('setup_link_issued', "Account setup link issued for {$user->name}", $user, [
                'delivery' => $delivery->value,
            ], $actor);
        });

        return route('account-setup.show', ['token' => $token]);
    }
}
