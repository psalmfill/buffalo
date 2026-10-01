<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\RecordDissemination;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\Contracts\Disseminable;
use App\Support\Jurisdiction\DisseminationSubjects;
use App\Support\Jurisdiction\JurisdictionGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Dissemination of threat reports and flashpoints (Phase 7A). Every ability
 * needs its permission AND the "who" rule:
 *
 * | Ability     | Who                                                                  |
 * |-------------|----------------------------------------------------------------------|
 * | disseminate | owners: SD/DSD of the owning state, DG, DDGs covering it              |
 * | onward      | SD/DSD of a recipient state, to LGAs of their own state only           |
 * | request     | the LGA chief of the owning LGA, or the record's creator (LGA chief)   |
 * | acknowledge | a recipient: the target LGA's chief, or the target state's SD/DSD      |
 * | renew       | flashpoints: SD/DSD of the disseminating state, DG, DDGs covering it   |
 * | revoke      | the disseminator, the SD of the owning state, DG, DDGs covering it     |
 *
 * Owners also need the record's classification; recipients see it only
 * within their clearance (the scopes never widen classification).
 */
final class DisseminationPolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
    ) {}

    /** The inbox. */
    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::DisseminationsView->value);
    }

    /** Disseminate a record the actor owns to other states or LGAs. */
    public function disseminate(User $actor, Model&Disseminable $subject): bool
    {
        return $actor->checkPermissionTo(Permission::DisseminationsCreate->value)
            && $this->owns($actor, $subject);
    }

    /** Forward a dissemination the actor's state received to one of its own LGAs. */
    public function onward(User $actor, RecordDissemination $parent): bool
    {
        return $actor->checkPermissionTo(Permission::DisseminationsCreate->value)
            && in_array($actor->getRole(), [Role::StateDirector, Role::DeputyStateDirector], true)
            && $actor->state_id === $parent->target_state_id
            && $parent->isActive()
            && $this->canSeeSubject($actor, $parent);
    }

    /** Ask the State Director or Deputy to disseminate. */
    public function request(User $actor, Model&Disseminable $subject): bool
    {
        return $actor->checkPermissionTo(Permission::DisseminationsRequest->value)
            && ($this->jurisdiction->canAccessOwn($actor, $subject) || (int) $subject->getAttribute('created_by') === $actor->id)
            && $actor->can('view', $subject);
    }

    /** Action or decline a dissemination request: an owner who may disseminate. */
    public function decideRequest(User $actor, Model&Disseminable $subject): bool
    {
        return $this->disseminate($actor, $subject);
    }

    public function acknowledge(User $actor, RecordDissemination $dissemination): bool
    {
        return $actor->checkPermissionTo(Permission::DisseminationsView->value)
            && $dissemination->isActive()
            && $dissemination->acknowledged_at === null
            && $this->receives($actor, $dissemination)
            && $this->canSeeSubject($actor, $dissemination)
            && ! $this->holdsSubject($actor, $dissemination);
    }

    public function renew(User $actor, RecordDissemination $dissemination): bool
    {
        if ($dissemination->subject_type !== 'flashpoint' || $dissemination->revoked_at !== null
            || ! $actor->checkPermissionTo(Permission::DisseminationsCreate->value)) {
            return false;
        }

        return match ($actor->getRole()) {
            Role::StateDirector, Role::DeputyStateDirector => $actor->state_id === $this->disseminatingState($dissemination)
                && $this->canSeeSubject($actor, $dissemination),
            Role::DirectorGeneral, Role::DeputyDirectorGeneral => $this->ownsSubject($actor, $dissemination),
            default => false,
        };
    }

    public function revoke(User $actor, RecordDissemination $dissemination): bool
    {
        if ($dissemination->revoked_at !== null || ! $actor->checkPermissionTo(Permission::DisseminationsRevoke->value)) {
            return false;
        }

        if ($dissemination->disseminated_by === $actor->id) {
            return true;
        }

        return match ($actor->getRole()) {
            Role::StateDirector => $actor->state_id === $dissemination->source_state_id && $this->ownsSubject($actor, $dissemination),
            Role::DirectorGeneral, Role::DeputyDirectorGeneral => $this->ownsSubject($actor, $dissemination),
            default => false,
        };
    }

    /** Whether the dissemination reaches the actor as a recipient. */
    public function receives(User $actor, RecordDissemination $dissemination): bool
    {
        $query = DB::table('record_disseminations')->where('id', $dissemination->id);
        RecordDissemination::applyReceivedBy($query, $actor);

        return $query->exists();
    }

    private function owns(User $actor, Model&Disseminable $subject): bool
    {
        return in_array($actor->getRole(), [Role::DirectorGeneral, Role::DeputyDirectorGeneral, Role::StateDirector, Role::DeputyStateDirector], true)
            && $this->jurisdiction->canAccessOwn($actor, $subject)
            && $this->classification->canSee($actor, $subject);
    }

    private function ownsSubject(User $actor, RecordDissemination $dissemination): bool
    {
        $subject = $this->subject($dissemination);

        return $subject !== null && $this->owns($actor, $subject);
    }

    /** The record is in the actor's own jurisdiction: they are its owner, not a recipient. */
    private function holdsSubject(User $actor, RecordDissemination $dissemination): bool
    {
        $subject = $this->subject($dissemination);

        return $subject !== null && $this->jurisdiction->canAccessOwn($actor, $subject);
    }

    private function canSeeSubject(User $actor, RecordDissemination $dissemination): bool
    {
        $subject = $this->subject($dissemination);

        return $subject !== null && $actor->can('view', $subject);
    }

    /** The state that made this dissemination: the owner, or the recipient state forwarding it. */
    private function disseminatingState(RecordDissemination $dissemination): int
    {
        return $dissemination->parent_dissemination_id === null
            ? $dissemination->source_state_id
            : (int) RecordDissemination::query()->whereKey($dissemination->parent_dissemination_id)->value('target_state_id');
    }

    /** Read without the current viewer's scopes; authorised above for $actor. */
    private function subject(RecordDissemination $dissemination): (Model&Disseminable)|null
    {
        return DisseminationSubjects::find($dissemination);
    }
}
