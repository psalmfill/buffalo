<?php

namespace App\Actions\Flashpoints;

use App\Models\Flashpoint;
use App\Models\FlashpointIncident;
use App\Models\FlashpointSecurityNote;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Removes a flashpoint link (Phase 6A): the link row is soft-deleted with
 * who removed it, and a named event is logged on the flashpoint.
 */
final class UnlinkFromFlashpoint
{
    public function __invoke(Flashpoint $flashpoint, FlashpointIncident|FlashpointSecurityNote $link, User $actor): void
    {
        DB::transaction(function () use ($flashpoint, $link, $actor): void {
            [$event, $label, $key, $ulid] = $link instanceof FlashpointIncident
                ? ['incident_unlinked', 'Incident unlinked', 'incident', $link->incident->ulid]
                : ['security_note_unlinked', 'Security note unlinked', 'security_note', $link->securityNote->ulid];

            $link->forceFill(['unlinked_by' => $actor->id])->save();
            $link->delete();

            AuditLog::event($event, $label, $flashpoint, [$key => $ulid], $actor);
        });
    }
}
