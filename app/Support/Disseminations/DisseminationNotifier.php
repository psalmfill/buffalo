<?php

namespace App\Support\Disseminations;

use App\Enums\DisseminationTargetLevel;
use App\Enums\NotificationSeverity;
use App\Enums\Role;
use App\Events\DisseminationEnded;
use App\Models\Lga;
use App\Models\RecordDissemination;
use App\Models\State;
use App\Models\User;
use App\Notifications\DisseminationNotification;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\Contracts\Disseminable;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Jurisdiction\NotificationRecipients;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Sends dissemination notifications (Phase 7A). Recipients are checked one
 * by one: while access lasts they must be able to view the record (which
 * includes their clearance); after it ends (revoked, expired) their
 * clearance alone is checked, so nobody learns of a record above it.
 */
final class DisseminationNotifier
{
    public function __construct(
        private readonly NotificationRecipients $recipients,
        private readonly ClassificationGate $classification,
        private readonly JurisdictionGate $jurisdiction,
    ) {}

    /**
     * Users a dissemination reaches: the SD and DSD of the target state,
     * plus the chief of the target LGA.
     *
     * @return Collection<int, User>
     */
    public function targetUsers(RecordDissemination $dissemination): Collection
    {
        $state = State::query()->whereKey($dissemination->target_state_id)->first();
        $users = collect([Role::StateDirector, Role::DeputyStateDirector])
            ->flatMap(fn (Role $role) => $this->recipients->resolve($role, $state));

        if ($dissemination->target_level === DisseminationTargetLevel::Lga) {
            $users = $users->merge($this->recipients->resolve(Role::LgaSecurityChief, Lga::query()->whereKey($dissemination->target_lga_id)->first()));
        }

        return $users->unique('id')->values();
    }

    /**
     * The SD and DSD of the record's own state.
     *
     * @return Collection<int, User>
     */
    public function owners(Model&Disseminable $subject): Collection
    {
        $state = State::query()->whereKey($subject->getAttribute('state_id'))->first();

        return collect([Role::StateDirector, Role::DeputyStateDirector])
            ->flatMap(fn (Role $role) => $this->recipients->resolve($role, $state))
            ->unique('id')->values();
    }

    /**
     * @return Collection<int, User>
     */
    public function users(int ...$ids): Collection
    {
        return collect($this->recipients->users(array_values($ids))->all());
    }

    public function toRecipients(RecordDissemination $dissemination, Model&Disseminable $subject, string $event, NotificationSeverity $severity): int
    {
        $ended = in_array($event, ['revoked', 'expired'], true);
        // Officers who hold the record in their own jurisdiction (e.g. the
        // owning SD for a dissemination to an LGA of their state) are not
        // recipients. An onward dissemination is news only to the LGA it
        // targets: its state's SD and DSD already hold the parent.
        $users = $this->targetUsers($dissemination)
            ->reject(fn (User $user) => $this->jurisdiction->canAccessOwn($user, $subject))
            ->reject(fn (User $user) => $dissemination->parent_dissemination_id !== null
                && in_array($user->getRole(), [Role::StateDirector, Role::DeputyStateDirector], true));

        return $this->send($users, $subject, $event, $severity, $this->source($dissemination), $ended);
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  list<int>  $except
     */
    public function send(Collection $users, Model&Disseminable $subject, string $event, NotificationSeverity $severity, ?string $source = null, bool $clearanceOnly = false, ?string $place = null, array $except = []): int
    {
        $recipients = $users->unique('id')
            ->reject(fn (User $user) => in_array($user->id, $except, true))
            ->filter(fn (User $user) => $clearanceOnly ? $this->classification->canSee($user, $subject) : $user->can('view', $subject))
            ->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new DisseminationNotification(
                $event,
                $subject->disseminationPath(),
                $subject->disseminationLabel(),
                $subject->disseminationKind(),
                $source ?? $this->stateName((int) $subject->getAttribute('state_id')),
                $severity,
                $place,
            ));
        }

        return $recipients->count();
    }

    /** Close pages open through the dissemination, for every user it reached. */
    public function broadcastEnded(RecordDissemination $dissemination): void
    {
        foreach ($this->targetUsers($dissemination) as $user) {
            DisseminationEnded::dispatch($dissemination->ulid, $user->ulid);
        }
    }

    /** "Lagos State", or "Lagos State via Kano State" for an onward dissemination. */
    public function source(RecordDissemination $dissemination): string
    {
        $source = $this->stateName($dissemination->source_state_id);

        if ($dissemination->parent_dissemination_id !== null) {
            $via = (int) RecordDissemination::query()->whereKey($dissemination->parent_dissemination_id)->value('target_state_id');

            return "{$source} via ".$this->stateName($via);
        }

        return $source;
    }

    private function stateName(int $id): string
    {
        return State::query()->whereKey($id)->value('name').' State';
    }
}
