<?php

namespace App\Support\Flashpoints;

use App\Enums\FlashpointKind;
use App\Enums\Role;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\FlashpointNotification;
use App\Support\Jurisdiction\FlashpointProximity;
use App\Support\Jurisdiction\NotificationRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Flashpoint notices (Phase 6A).
 *
 * - incidentSubmitted(): an incident submitted inside the area of an active
 *   or monitoring flashpoint is reported to the SD and DSD of the
 *   flashpoint's state and the chief of its LGA, once per flashpoint and
 *   incident, and only to those who can see BOTH.
 * - checkEscalation(): a blackspot with at least
 *   `escalation_incident_count` linked or nearby incidents within
 *   `escalation_window_days` triggers one reassessment notice per window,
 *   to the SD and DSD of its state who can see the blackspot.
 *
 * Areas are matched whatever the submitter may see (FlashpointProximity).
 */
final class FlashpointAlerts
{
    public function __construct(
        private readonly FlashpointProximity $proximity,
        private readonly NotificationRecipients $recipients,
    ) {}

    public function incidentSubmitted(Incident $incident): void
    {
        $ids = $this->proximity->watchedContaining($incident->id);

        if ($ids === []) {
            return;
        }

        foreach ($this->proximity->load($ids) as $flashpoint) {
            $first = DB::table('flashpoint_incident_alerts')->insertOrIgnore([
                'flashpoint_id' => $flashpoint->id,
                'incident_id' => $incident->id,
                'alerted_at' => CarbonImmutable::now(),
            ]);

            if ($first === 0) {
                continue;
            }

            $users = $this->owners($flashpoint)->filter(fn (User $user) => $user->can('view', $flashpoint) && $user->can('view', $incident));

            if ($users->isNotEmpty()) {
                Notification::send($users, FlashpointNotification::about('incident_nearby', $flashpoint, (string) $incident->reference_no));
            }
        }

        $this->checkEscalation($ids);
    }

    /**
     * @param  list<int>  $flashpointIds
     */
    public function checkEscalation(array $flashpointIds): void
    {
        $window = (int) config('buffalo.flashpoints.escalation_window_days');
        $threshold = (int) config('buffalo.flashpoints.escalation_incident_count');
        $since = CarbonImmutable::now()->subDays($window);

        foreach ($this->proximity->load($flashpointIds) as $flashpoint) {
            if ($flashpoint->kind !== FlashpointKind::Blackspot || ! $flashpoint->status->isWatched()) {
                continue;
            }

            if ($this->proximity->recentIncidentCount($flashpoint->id, $since) < $threshold) {
                continue;
            }

            // Once per window, even under concurrent submissions.
            $claimed = DB::table('flashpoints')
                ->where('id', $flashpoint->id)
                ->where(fn ($q) => $q->whereNull('escalation_notified_at')->orWhere('escalation_notified_at', '<=', $since))
                ->update(['escalation_notified_at' => CarbonImmutable::now()]);

            if ($claimed === 0) {
                continue;
            }

            $users = $this->owners($flashpoint, withChief: false)->filter(fn (User $user) => $user->can('view', $flashpoint));

            if ($users->isNotEmpty()) {
                Notification::send($users, FlashpointNotification::about('reassessment_due', $flashpoint));
            }
        }
    }

    /**
     * The SD and DSD of the flashpoint's state, and the chief of its LGA.
     *
     * @return Collection<int, User>
     */
    private function owners(Flashpoint $flashpoint, bool $withChief = true): Collection
    {
        return collect([Role::StateDirector, Role::DeputyStateDirector, ...($withChief ? [Role::LgaSecurityChief] : [])])
            ->flatMap(fn (Role $role) => $this->recipients->resolve($role, $flashpoint->lga))
            ->unique('id')
            ->values();
    }
}
