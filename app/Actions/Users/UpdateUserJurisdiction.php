<?php

namespace App\Actions\Users;

use App\Actions\Reports\PauseReportSchedules;
use App\Enums\Role;
use App\Enums\SchedulePauseReason;
use App\Models\User;
use App\Notifications\ReportSchedulesPaused;
use App\Support\Audit\AuditLog;
use App\Support\Users\SuperAdmins;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes a user's role and/or jurisdiction. Zones are cleared for any role
 * other than DDG. The last active super admin keeps their role. A change
 * pauses the user's report schedules and asks them to review (Phase 8B.5).
 *
 * Authorisation (UserPolicy::changeRole) is the caller's job.
 */
final class UpdateUserJurisdiction
{
    public function __construct(
        private readonly AssignRole $assignRole,
        private readonly SuperAdmins $superAdmins,
        private readonly OfficerPostings $postings,
        private readonly PauseReportSchedules $pauseSchedules,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(User $user, UserJurisdiction $jurisdiction, ?User $actor = null): User
    {
        $jurisdiction->validate();

        $paused = 0;

        $user = DB::transaction(function () use ($user, $jurisdiction, $actor, &$paused): User {
            if ($jurisdiction->role !== Role::SuperAdmin && $this->superAdmins->isLastActive($user, lockForUpdate: true)) {
                throw ValidationException::withMessages([
                    'role' => 'The last active super admin cannot be given another role.',
                ]);
            }

            $before = ['role' => $user->getRole()?->value, 'jurisdiction' => $user->jurisdictionLabel()];
            $zonesBefore = $user->zones->modelKeys();
            sort($zonesBefore);
            $zonesAfter = $jurisdiction->zoneIds();
            sort($zonesAfter);

            $user->forceFill([
                ...$jurisdiction->attributes(),
                'updated_by' => $actor?->id,
            ]);

            $unchanged = ! $user->isDirty(['jurisdiction_level', 'state_id', 'lga_id'])
                && $before['role'] === $jurisdiction->role->value
                && $zonesBefore === $zonesAfter;

            if ($unchanged) {
                return $user;
            }

            $user->save();
            ($this->assignRole)($user, $jurisdiction->role);
            $user->zones()->sync($zonesAfter);
            $user->load('roles', 'zones', 'state', 'lga');

            // The posting history follows every role or jurisdiction change.
            $this->postings->closeOpen($user);

            if ($user->is_active) {
                $this->postings->open($user, $actor);
            }

            AuditLog::event('role_jurisdiction_changed', "Role or jurisdiction of {$user->name} changed", $user, [
                'old' => $before,
                'new' => ['role' => $jurisdiction->role->value, 'jurisdiction' => $user->jurisdictionLabel()],
            ], $actor);

            // A posting change pauses every report schedule until the
            // owner reviews it (Phase 8B.5).
            $paused = ($this->pauseSchedules)($user, SchedulePauseReason::PostingChange);

            return $user;
        });

        if ($paused > 0 && $user->is_active) {
            $user->notify(new ReportSchedulesPaused($paused));
        }

        return $user;
    }
}
