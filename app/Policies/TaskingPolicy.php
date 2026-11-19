<?php

namespace App\Policies;

use App\Enums\JurisdictionLevel;
use App\Enums\Permission;
use App\Models\Lga;
use App\Models\Tasking;
use App\Models\TaskingRecipient;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Taskings (Phase 11H). Always with the jurisdiction scope (the tasking's
 * state; for an LGA chief, a recipient row of their LGA) and the
 * classification gate. Never grantable or disseminable: every check uses
 * the user's OWN jurisdiction.
 *
 * | Ability                     | Who                                                    |
 * |-----------------------------|--------------------------------------------------------|
 * | view a sent tasking         | taskings.view within reach (LGA chiefs: own LGA a recipient) |
 * | view a draft                | the state's directors (taskings.send) only             |
 * | see every recipient's row   | state-level and national users (never an LGA chief)     |
 * | create, edit/send a draft   | taskings.send, state-level, own state                  |
 * | recall, add a follow-up note| taskings.send, own state, while sent                    |
 * | acknowledge, complete, respond | taskings.respond, own LGA a recipient, while sent    |
 */
final class TaskingPolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::TaskingsView->value);
    }

    public function view(User $actor, Tasking $tasking): bool
    {
        if (! $this->visible($actor, $tasking)) {
            return false;
        }

        return ! $tasking->isDraft() || $this->isStateDirector($actor, $tasking);
    }

    /** The recipients table and every LGA's response: never an LGA chief. */
    public function viewRecipients(User $actor, Tasking $tasking): bool
    {
        return $this->view($actor, $tasking) && $actor->jurisdiction_level !== JurisdictionLevel::Lga;
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::TaskingsSend->value)
            && $actor->jurisdiction_level === JurisdictionLevel::State
            && $actor->state_id !== null;
    }

    /** Sending to one LGA (pre-ticked from the LGA page). */
    public function sendTo(User $actor, Lga $lga): bool
    {
        return $this->create($actor) && $lga->state_id === $actor->state_id && $lga->is_active;
    }

    public function update(User $actor, Tasking $tasking): bool
    {
        return $tasking->isDraft() && $this->isStateDirector($actor, $tasking);
    }

    public function send(User $actor, Tasking $tasking): bool
    {
        return $this->update($actor, $tasking);
    }

    public function delete(User $actor, Tasking $tasking): bool
    {
        return $this->update($actor, $tasking);
    }

    public function recall(User $actor, Tasking $tasking): bool
    {
        return $tasking->isSent() && $this->isStateDirector($actor, $tasking);
    }

    public function addNote(User $actor, Tasking $tasking): bool
    {
        return $this->recall($actor, $tasking);
    }

    /** Acknowledge, complete and further responses: the recipient LGA's chief. */
    public function respond(User $actor, TaskingRecipient $recipient): bool
    {
        $tasking = $recipient->tasking;

        return $tasking !== null
            && $tasking->isSent()
            && $actor->checkPermissionTo(Permission::TaskingsRespond->value)
            && $actor->jurisdiction_level === JurisdictionLevel::Lga
            && $actor->lga_id !== null
            && $recipient->lga_id === $actor->lga_id
            && $this->visible($actor, $tasking);
    }

    private function visible(User $actor, Tasking $tasking): bool
    {
        return $actor->checkPermissionTo(Permission::TaskingsView->value)
            && $this->jurisdiction->canAccessOwn($actor, $tasking)
            && $this->classification->canSee($actor, $tasking);
    }

    /** A director (SD or DSD) of the tasking's own state. */
    private function isStateDirector(User $actor, Tasking $tasking): bool
    {
        return $this->create($actor)
            && $actor->state_id === $tasking->state_id
            && $this->visible($actor, $tasking);
    }
}
