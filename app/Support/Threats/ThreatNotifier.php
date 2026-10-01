<?php

namespace App\Support\Threats;

use App\Enums\NotificationSeverity;
use App\Enums\Role;
use App\Models\ThreatReport;
use App\Models\User;
use App\Notifications\ThreatNotification;
use App\Support\Jurisdiction\NotificationRecipients;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Sends threat report notifications (Phase 6B) only to recipients who can
 * see the threat report (jurisdiction and classification, per recipient).
 */
final class ThreatNotifier
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    /**
     * The SD and DSD of the threat's state.
     *
     * @return Collection<int, User>
     */
    public function stateLeads(ThreatReport $threat): Collection
    {
        return $this->roles($threat, [Role::StateDirector, Role::DeputyStateDirector]);
    }

    /**
     * The SD, DSD and the chief of the threat's LGA.
     *
     * @return Collection<int, User>
     */
    public function local(ThreatReport $threat): Collection
    {
        return $this->roles($threat, [Role::StateDirector, Role::DeputyStateDirector, Role::LgaSecurityChief]);
    }

    /**
     * The DG and the DDGs covering the threat's state.
     *
     * @return Collection<int, User>
     */
    public function national(ThreatReport $threat): Collection
    {
        return $this->roles($threat, [Role::DirectorGeneral, Role::DeputyDirectorGeneral]);
    }

    /**
     * @return Collection<int, User>
     */
    public function creator(ThreatReport $threat): Collection
    {
        return collect($this->recipients->users([$threat->created_by])->all());
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  list<int>  $except  user ids left out (e.g. the actor)
     */
    public function send(ThreatReport $threat, string $event, Collection $users, NotificationSeverity $severity, array $except = []): int
    {
        $threat->loadMissing(['threatType', 'lga']);

        $recipients = $users->unique('id')
            ->reject(fn (User $user) => in_array($user->id, $except, true))
            ->filter(fn (User $user) => $user->can('view', $threat))
            ->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, ThreatNotification::about($event, $threat, $severity));
        }

        return $recipients->count();
    }

    /**
     * The "immediate" set: local leads plus national.
     *
     * @param  list<int>  $except
     */
    public function sendImmediate(ThreatReport $threat, string $event, array $except = []): int
    {
        return $this->send($threat, $event, $this->local($threat)->merge($this->national($threat)), NotificationSeverity::Critical, $except);
    }

    /**
     * @param  list<Role>  $roles
     * @return Collection<int, User>
     */
    private function roles(ThreatReport $threat, array $roles): Collection
    {
        $threat->loadMissing('lga');

        return collect($roles)->flatMap(fn (Role $role) => $this->recipients->resolve($role, $threat->lga))->values();
    }
}
