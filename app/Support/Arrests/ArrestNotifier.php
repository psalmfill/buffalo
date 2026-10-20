<?php

namespace App\Support\Arrests;

use App\Enums\Role;
use App\Models\Arrest;
use App\Models\State;
use App\Models\User;
use App\Notifications\ArrestNotification;
use App\Support\Jurisdiction\NotificationRecipients;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears of a new arrest (Phase 11C), always only users who can see the
 * arrest, never the actor:
 * - a person of interest among the arrested: the SD and DSD of each POI's
 *   owning state;
 * - an arrest linked to an escalated incident: the DG and the DDGs
 *   covering the arrest's state.
 */
final class ArrestNotifier
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    public function created(Arrest $arrest, User $actor): void
    {
        $arrest->loadMissing('lga');

        // Owning states of the arrested POIs (read as data: only the state id).
        $stateIds = DB::table('arrest_persons')
            ->join('persons_of_interest', 'persons_of_interest.person_id', '=', 'arrest_persons.person_id')
            ->where('arrest_persons.arrest_id', $arrest->id)
            ->whereNull('arrest_persons.deleted_at')
            ->whereNull('persons_of_interest.deleted_at')
            ->distinct()
            ->pluck('persons_of_interest.state_id');

        if ($stateIds->isNotEmpty()) {
            $users = State::query()->whereKey($stateIds->all())->get()->flatMap(fn (State $state) => [
                ...$this->recipients->resolve(Role::StateDirector, $state),
                ...$this->recipients->resolve(Role::DeputyStateDirector, $state),
            ]);
            $this->send($arrest, 'poi_arrested', collect($users), $actor);
        }

        $escalated = $arrest->incident_id !== null
            && DB::table('incidents')->where('id', $arrest->incident_id)->whereNotNull('escalated_at')->exists();

        if ($escalated) {
            $users = [
                ...$this->recipients->resolve(Role::DirectorGeneral, $arrest->lga),
                ...$this->recipients->resolve(Role::DeputyDirectorGeneral, $arrest->lga),
            ];
            $this->send($arrest, 'escalated_incident', collect($users), $actor);
        }
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function send(Arrest $arrest, string $event, Collection $users, User $actor): int
    {
        $recipients = $users->unique('id')
            ->reject(fn (User $user) => $user->id === $actor->id)
            ->filter(fn (User $user) => $user->can('view', $arrest))
            ->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, ArrestNotification::about($event, $arrest));
        }

        return $recipients->count();
    }
}
