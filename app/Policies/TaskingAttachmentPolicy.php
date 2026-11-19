<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Tasking;
use App\Models\TaskingAttachment;
use App\Models\TaskingRecipient;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Tasking attachments (Phase 11H): `taskings.view`, the attachment's own
 * jurisdiction (a report back's: its LGA) and classification, and its
 * tasking visible to the user. Never grantable or disseminable.
 *
 * - On the tasking: added, edited and removed while it is a draft, by the
 *   state's directors (TaskingPolicy::update).
 * - On a report back: added by the recipient LGA's chief while the
 *   tasking is sent; edited and removed by them until the LGA completes it
 *   (files sent with a further response stay).
 * - Viewing and downloading: anyone who may see it (no new permission);
 *   originals above `restricted` also need step-up.
 */
final class TaskingAttachmentPolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
    ) {}

    public function view(User $actor, TaskingAttachment $attachment): bool
    {
        // The relation is scoped: a tasking out of reach loads as null.
        $tasking = $attachment->tasking;

        return $tasking !== null
            && $actor->checkPermissionTo(Permission::TaskingsView->value)
            && $this->jurisdiction->canAccessOwn($actor, $attachment)
            && $this->classification->canSee($actor, $attachment)
            && $actor->can('view', $tasking);
    }

    public function upload(User $actor, Tasking $tasking): bool
    {
        return $actor->can('update', $tasking);
    }

    public function uploadToResponse(User $actor, TaskingRecipient $recipient): bool
    {
        return $actor->can('respond', $recipient);
    }

    public function update(User $actor, TaskingAttachment $attachment): bool
    {
        if (! $this->view($actor, $attachment) || $attachment->tasking === null) {
            return false;
        }

        if ($attachment->isOnTasking()) {
            return $actor->can('update', $attachment->tasking);
        }

        $recipient = $attachment->recipient;

        return $recipient !== null && ! $recipient->isCompleted() && $actor->can('respond', $recipient);
    }

    public function delete(User $actor, TaskingAttachment $attachment): bool
    {
        return $this->update($actor, $attachment);
    }

    public function download(User $actor, TaskingAttachment $attachment): bool
    {
        return $this->view($actor, $attachment);
    }
}
