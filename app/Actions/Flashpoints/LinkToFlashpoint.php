<?php

namespace App\Actions\Flashpoints;

use App\Models\CommunitySecurityNote;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Flashpoints\FlashpointAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Links an incident or a community security note to a flashpoint (Phase
 * 6A). The link sits on the flashpoint's place. Logged as a named event on
 * the flashpoint (ULIDs only). Linking an incident to a blackspot may
 * trigger the reassessment notice (FlashpointAlerts).
 *
 * Incident links are not affected by the incident lock: closed incidents
 * can be linked (the incident itself is not changed).
 */
final class LinkToFlashpoint
{
    public function __construct(private readonly FlashpointAlerts $alerts) {}

    public function __invoke(Flashpoint $flashpoint, Incident|CommunitySecurityNote $record, ?string $note, User $actor): void
    {
        [$table, $column, $event, $label] = $record instanceof Incident
            ? ['flashpoint_incidents', 'incident_id', 'incident_linked', 'Incident linked']
            : ['flashpoint_security_notes', 'community_security_note_id', 'security_note_linked', 'Security note linked'];

        DB::transaction(function () use ($flashpoint, $record, $note, $actor, $table, $column, $event, $label): void {
            $inserted = DB::table($table)->insertOrIgnore([
                'ulid' => Str::lower((string) Str::ulid()),
                'flashpoint_id' => $flashpoint->id,
                'state_id' => $flashpoint->state_id,
                'lga_id' => $flashpoint->lga_id,
                $column => $record->id,
                'link_note' => $note,
                'linked_by' => $actor->id,
                'linked_at' => CarbonImmutable::now(),
            ]);

            if ($inserted === 0) {
                throw ValidationException::withMessages(['record' => 'This record is already linked to the flashpoint.']);
            }

            AuditLog::event($event, $label, $flashpoint, [
                $record instanceof Incident ? 'incident' : 'security_note' => $record->ulid,
                ...($note !== null ? ['note' => $note] : []),
            ], $actor);
        });

        if ($record instanceof Incident) {
            $this->alerts->checkEscalation([$flashpoint->id]);
        }
    }
}
