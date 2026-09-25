<?php

namespace App\Actions\Auth;

use App\Models\PasswordHistory;
use App\Models\User;
use App\Support\Auth\PasswordRules;
use Illuminate\Support\Facades\DB;

/**
 * The only place a user-chosen password is stored. Validate with
 * PasswordRules::rules($user) first. Records the password in the history,
 * keeps only the last PasswordRules::HISTORY entries and sets
 * password_changed_at.
 */
final class SetUserPassword
{
    public function __invoke(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill([
                'password' => $password,
                'password_changed_at' => now(),
            ])->save();

            $user->passwordHistories()->create(['password' => $user->getAuthPassword()]);

            $keep = $user->passwordHistories()->latest('id')->limit(PasswordRules::HISTORY)->pluck('id');

            PasswordHistory::query()
                ->where('user_id', $user->id)
                ->whereNotIn('id', $keep)
                ->delete();
        });
    }
}
