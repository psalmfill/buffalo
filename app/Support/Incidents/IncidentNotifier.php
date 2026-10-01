<?php

namespace App\Support\Incidents;

use App\Enums\NotificationSeverity;
use App\Enums\Role;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\IncidentNotification;
use App\Support\Jurisdiction\NotificationRecipients;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Sends incident notifications (Phase 5C) only to recipients who can see
 * the record concerned (the incident, or a follow-up): jurisdiction and
 * classification are checked per recipient before sending.
 */
final class IncidentNotifier
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    /**
     * The State Director and Deputy of the incident's state.
     *
     * @return Collection<int, User>
     */
    public function stateLeads(Incident $incident): Collection
    {
        return $this->recipients->resolve(Role::StateDirector, $incident->lga)
            ->merge($this->recipients->resolve(Role::DeputyStateDirector, $incident->lga));
    }

    /**
     * The DG and the DDGs covering the incident's state.
     *
     * @return Collection<int, User>
     */
    public function national(Incident $incident): Collection
    {
        return $this->recipients->resolve(Role::DirectorGeneral, $incident->lga)
            ->merge($this->recipients->resolve(Role::DeputyDirectorGeneral, $incident->lga));
    }

    /**
     * The reporting officer, and the creator when different.
     *
     * @return Collection<int, User>
     */
    public function officers(Incident $incident): Collection
    {
        return $this->recipients->users([$incident->reporting_officer_id, $incident->created_by]);
    }

    /**
     * Named users, active only.
     *
     * @param  list<int|null>  $ids
     * @return Collection<int, User>
     */
    public function users(array $ids): Collection
    {
        return $this->recipients->users($ids);
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Model|null  $record  what the recipient must be able to see (default the incident)
     * @param  list<int>  $except  user ids left out (e.g. the author)
     * @return int recipients notified
     */
    public function send(Incident $incident, string $event, Collection $users, NotificationSeverity $severity = NotificationSeverity::Info, ?Model $record = null, array $except = []): int
    {
        $record ??= $incident;

        $recipients = $users->unique('id')
            ->reject(fn (User $user) => in_array($user->id, $except, true))
            ->filter(fn (User $user) => $user->can('view', $record))
            ->values();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $names = DB::table('incident_types')->where('id', $incident->incident_type_id)->value('name');
        $lga = DB::table('lgas')->where('id', $incident->lga_id)->value('name');

        Notification::send($recipients, new IncidentNotification(
            $event,
            $incident->ulid,
            (string) $incident->reference_no,
            (string) $names,
            (string) $lga,
            $severity,
        ));

        return $recipients->count();
    }
}
