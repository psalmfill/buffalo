<?php

namespace App\Actions\Auth;

use App\Enums\LoginEvent;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use App\Support\Auth\LoginHistoryRecorder;
use App\Support\Jurisdiction\JurisdictionContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Counts a failed password or two-factor attempt. After max_attempts
 * consecutive failures the account is locked: 15 minutes, doubling for each
 * further lock within 24 hours, capped at 24 hours. Active super admins are
 * notified. The counter lives on the user row and is updated under a row
 * lock, so parallel attempts cannot slip past the limit.
 */
final class RecordFailedLogin
{
    public function __construct(
        private readonly LoginHistoryRecorder $history,
        private readonly JurisdictionContext $context,
    ) {}

    public function __invoke(User $user): void
    {
        $locked = DB::transaction(function () use ($user): ?CarbonInterface {
            $row = DB::table('users')->where('id', $user->id)->lockForUpdate()
                ->first(['failed_login_count', 'lockout_count', 'last_locked_at', 'locked_until']);

            if ($row === null) {
                return null;
            }

            // Attempts while already locked do not count again.
            if ($row->locked_until !== null && Carbon::parse($row->locked_until)->isFuture()) {
                return null;
            }

            $failures = (int) $row->failed_login_count + 1;

            if ($failures < (int) config('buffalo.auth.lockout.max_attempts', 5)) {
                DB::table('users')->where('id', $user->id)->update(['failed_login_count' => $failures]);

                return null;
            }

            $withinWindow = $row->last_locked_at !== null
                && Carbon::parse($row->last_locked_at)->gt(now()->subHours((int) config('buffalo.auth.lockout.window_hours', 24)));
            $lockouts = $withinWindow ? (int) $row->lockout_count + 1 : 1;
            $minutes = min(
                (int) config('buffalo.auth.lockout.base_minutes', 15) * (2 ** ($lockouts - 1)),
                (int) config('buffalo.auth.lockout.max_minutes', 1440),
            );
            $until = now()->addMinutes($minutes);

            DB::table('users')->where('id', $user->id)->update([
                'failed_login_count' => 0,
                'lockout_count' => $lockouts,
                'last_locked_at' => now(),
                'locked_until' => $until,
            ]);

            return $until;
        });

        $fresh = DB::table('users')->where('id', $user->id)->first(['failed_login_count', 'lockout_count', 'last_locked_at', 'locked_until']);
        $user->forceFill((array) $fresh)->syncOriginal();

        if ($locked !== null) {
            $this->history->record(LoginEvent::Locked, $user);
            $this->notifySuperAdmins($user, $locked);
        }
    }

    private function notifySuperAdmins(User $user, CarbonInterface $until): void
    {
        // This runs in a guest login request (jurisdiction context `none`),
        // so the super admins are loaded through the audited system bypass.
        $admins = $this->context->runAsSystem(
            'Notify super admins that an account was locked',
            fn () => User::query()
                ->role(Role::SuperAdmin->value)
                ->where('is_active', true)
                ->get(),
        );

        Notification::send($admins, new AccountLockedNotification($user, $until, request()->ip()));
    }
}
