<?php

namespace App\Actions\Blackspots;

use App\Models\BlackspotSuggestion;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records that a suggested blackspot became a flashpoint (Phase 6C), inside
 * the flashpoint's creating transaction: the member incidents the officer
 * can see (not drafts or invalid) are linked to it, and the suggestion is
 * marked accepted. Members the officer cannot see are not linked.
 */
final class AcceptBlackspotSuggestion
{
    public function __invoke(BlackspotSuggestion $suggestion, Flashpoint $flashpoint, User $actor): int
    {
        $incidents = Incident::query()
            ->whereNotIn('incidents.status', ['draft', 'invalid'])
            ->whereIn('incidents.id', DB::table('blackspot_suggestion_incidents')->where('blackspot_suggestion_id', $suggestion->id)->select('incident_id'))
            ->pluck('incidents.id')
            ->all();
        $now = CarbonImmutable::now();

        DB::table('flashpoint_incidents')->insertOrIgnore(array_map(fn (int $id) => [
            'ulid' => Str::lower((string) Str::ulid()),
            'flashpoint_id' => $flashpoint->id,
            'state_id' => $flashpoint->state_id,
            'lga_id' => $flashpoint->lga_id,
            'incident_id' => $id,
            'link_note' => 'From a suggested blackspot.',
            'linked_by' => $actor->id,
            'linked_at' => $now,
        ], $incidents));

        DB::table('blackspot_suggestions')->where('id', $suggestion->id)->update([
            'status' => 'accepted',
            'decided_by' => $actor->id,
            'decided_at' => $now,
            'flashpoint_id' => $flashpoint->id,
        ]);

        AuditLog::event('blackspot_suggestion_accepted', 'Suggested blackspot accepted', $flashpoint, ['suggestion' => $suggestion->ulid, 'incidents_linked' => count($incidents)], $actor);

        return count($incidents);
    }
}
