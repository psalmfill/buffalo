<?php

namespace App\Actions\Rollout;

use App\Models\State;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Switches a state live, or back to not live (Phase 9B.3), with a required
 * reason recorded in the audit log. The change itself (is_live,
 * went_live_at) is also in the model's activity log. Switching a state off
 * ends its users' sessions on their next request (EnsureStateIsLive).
 */
final class SetStateLive
{
    /**
     * @throws ValidationException
     */
    public function __invoke(State $state, bool $live, string $reason, User $actor): State
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'A reason of at most 1000 characters is required.']);
        }

        if ($state->is_live === $live) {
            throw ValidationException::withMessages(['state' => $live ? "{$state->name} is already live." : "{$state->name} is already not live."]);
        }

        return DB::transaction(function () use ($state, $live, $reason, $actor): State {
            $state->forceFill([
                'is_live' => $live,
                'went_live_at' => $live ? now() : null,
            ])->save();

            AuditLog::event(
                $live ? 'state_went_live' : 'state_taken_offline',
                $live ? "{$state->name} went live" : "{$state->name} set back to not live",
                $state,
                ['reason' => $reason],
                $actor,
            );

            return $state;
        });
    }
}
