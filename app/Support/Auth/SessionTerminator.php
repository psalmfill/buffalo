<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends every session of a user, whatever the session driver: bumping
 * session_version makes EnforceSessionPolicy log out every existing session
 * at its next request. Remember-me tokens are rotated as well.
 *
 * The version is incremented atomically in SQL, never from the in-memory
 * model (which may be stale or lack the column), so it can only go up.
 */
final class SessionTerminator
{
    public function terminateAll(User $user): void
    {
        DB::table('users')->where('id', $user->id)->update([
            'session_version' => DB::raw('session_version + 1'),
            'session_token' => null,
            'remember_token' => Str::random(60),
            'updated_at' => now(),
        ]);

        $fresh = DB::table('users')->where('id', $user->id)->first(['session_version', 'session_token', 'remember_token']);

        $user->forceFill((array) $fresh)->syncOriginalAttributes(['session_version', 'session_token', 'remember_token']);
    }
}
