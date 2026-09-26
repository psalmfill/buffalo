<?php

namespace App\Actions\CommunityInformation;

use App\Enums\SecurityNoteStatus;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use App\Models\Lga;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Creates, edits and changes the status of community security notes.
 * Classification changes are logged by HasClassification; status changes
 * are logged here as named events. The level must already have been
 * checked with ClassificationGate::canAssign (SecurityNoteRequest).
 */
final class SaveSecurityNote
{
    /**
     * @param  array{community: Community, category: string, title: string, description: string, classification: string, observed_at: string, status: string, resolved_at: string|null}  $data
     */
    public function create(Lga $lga, array $data, User $actor): CommunitySecurityNote
    {
        $note = new CommunitySecurityNote;
        $note->forceFill([
            'community_id' => $data['community']->id,
            'lga_id' => $lga->id,
            'state_id' => $lga->state_id,
            'category' => $data['category'],
            'title' => trim($data['title']),
            'description' => $data['description'],
            'classification' => $data['classification'],
            'observed_at' => $data['observed_at'],
            'status' => $data['status'],
            'resolved_at' => $data['status'] === SecurityNoteStatus::Resolved->value ? $data['resolved_at'] : null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();

        return $note;
    }

    /**
     * @param  array{community: Community, category: string, title: string, description: string, classification: string, observed_at: string}  $data
     */
    public function update(CommunitySecurityNote $note, array $data, User $actor): void
    {
        $note->forceFill([
            'community_id' => $data['community']->id,
            'category' => $data['category'],
            'title' => trim($data['title']),
            'description' => $data['description'],
            'classification' => $data['classification'],
            'observed_at' => $data['observed_at'],
        ]);

        if ($note->isDirty()) {
            $note->forceFill(['updated_by' => $actor->id])->save();
        }
    }

    public function changeStatus(CommunitySecurityNote $note, SecurityNoteStatus $status, ?string $resolvedAt, User $actor): void
    {
        $old = $note->status;

        if ($old === $status && ($status !== SecurityNoteStatus::Resolved || $note->resolved_at?->toDateString() === $resolvedAt)) {
            return;
        }

        DB::transaction(function () use ($note, $status, $resolvedAt, $actor, $old): void {
            $note->forceFill([
                'status' => $status,
                'resolved_at' => $status === SecurityNoteStatus::Resolved ? $resolvedAt : null,
                'updated_by' => $actor->id,
            ])->save();

            AuditLog::event('status_changed', "Security note status changed from {$old->value} to {$status->value}", $note, [
                'old' => $old->value,
                'new' => $status->value,
            ], $actor);
        });
    }
}
