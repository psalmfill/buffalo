<?php

namespace App\Support\Auth;

use App\Exceptions\StepUpRequired;
use Illuminate\Http\Request;

/**
 * Step-up authentication (Phase 8B.2): proof that the person at the
 * keyboard is the account holder, even within a valid session. A TOTP
 * code confirmed within the last `buffalo.step_up.minutes` (15) passes;
 * otherwise sensitive actions throw StepUpRequired, which the frontend
 * answers with a TOTP dialog before retrying. Recovery codes are never
 * accepted (they are kept for lost devices).
 */
final class StepUp
{
    public const SESSION_KEY = 'buffalo.step_up_at';

    public static function minutes(): int
    {
        return (int) config('buffalo.step_up.minutes', 15);
    }

    public static function isRecent(Request $request): bool
    {
        $at = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return is_int($at) && now()->getTimestamp() - $at < self::minutes() * 60;
    }

    /**
     * @throws StepUpRequired
     */
    public static function ensure(Request $request): void
    {
        if (! self::isRecent($request)) {
            throw new StepUpRequired;
        }
    }

    public static function confirm(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
    }

    /** When the current confirmation expires, if any. */
    public static function expiresAt(Request $request): ?int
    {
        return self::isRecent($request) ? (int) $request->session()->get(self::SESSION_KEY) + self::minutes() * 60 : null;
    }
}
