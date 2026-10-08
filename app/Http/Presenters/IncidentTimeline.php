<?php

namespace App\Http\Presenters;

use App\Enums\AccessAction;
use App\Models\Incident;
use App\Models\IncidentConsolidation;
use App\Models\IncidentEvidence;
use App\Models\IncidentStatusHistory;
use App\Models\IncidentUpdate;
use App\Support\Incidents\Consolidation;
use App\Support\Jurisdiction\OfficerNames;
use Illuminate\Support\Facades\DB;

/**
 * The incident Timeline tab (Phase 5C): status changes, follow-ups,
 * escalation and acknowledgement, and evidence uploads, in time order, each
 * with its author and time. Every source is queried through its scopes, so
 * follow-ups and evidence above the viewer's clearance are absent. Viewing
 * a classified follow-up here is access-logged.
 */
final class IncidentTimeline
{
    /**
     * @return list<array{at: string, kind: string, label: string, body: string|null, who: string|null, classification: array{value: string, label: string}|null, origin: string|null}>
     */
    public static function for(Incident $incident): array
    {
        $histories = IncidentStatusHistory::query()->where('incident_id', $incident->id)->orderBy('changed_at')->orderBy('id')->get();
        $updates = IncidentUpdate::query()->where('incident_id', $incident->id)->orderBy('created_at')->orderBy('id')->get();
        $evidence = IncidentEvidence::query()->where('incident_id', $incident->id)->orderBy('created_at')->get(['id', 'ulid', 'incident_id', 'title', 'evidence_type', 'classification', 'uploaded_by', 'created_by', 'created_at', 'state_id', 'lga_id']);

        $names = OfficerNames::for([
            ...$histories->map(fn (IncidentStatusHistory $h) => $h->changed_by),
            ...$updates->map(fn (IncidentUpdate $u) => $u->created_by),
            ...$evidence->map(fn (IncidentEvidence $e) => $e->uploaded_by),
            $incident->escalated_by,
            $incident->escalation_acknowledged_by,
        ]);

        $entries = [];

        foreach ($histories as $history) {
            $entries[] = [
                'at' => $history->changed_at->toIso8601String(),
                'kind' => 'status',
                'label' => $history->from_status === null || $history->from_status === $history->to_status
                    ? $history->to_status->label()
                    : "{$history->from_status->label()} → {$history->to_status->label()}",
                'body' => $history->comment,
                'who' => $history->changed_by === null ? null : $names->get($history->changed_by),
                'classification' => null,
            ];
        }

        foreach ($updates as $update) {
            if ($update->logsViews()) {
                $update->recordAccess(AccessAction::View, 'timeline');
            }

            $entries[] = [
                'at' => $update->created_at->toIso8601String(),
                'kind' => 'update_'.$update->type->value,
                'label' => $update->type->label(),
                'body' => $update->body,
                'who' => $names->get($update->created_by),
                // A follow-up copied from a duplicate (Phase 10B): its original author and time.
                'origin' => Consolidation::attribution($update),
                'classification' => ['value' => $update->classificationLevel()->value, 'label' => $update->classificationLevel()->label()],
            ];
        }

        if ($incident->escalated_at !== null) {
            $entries[] = [
                'at' => $incident->escalated_at->toIso8601String(),
                'kind' => 'escalated',
                'label' => 'Escalated to national level',
                'body' => $incident->escalation_reason,
                'who' => $incident->escalated_by === null ? 'Automatic' : $names->get($incident->escalated_by),
                'classification' => null,
            ];
        }

        if ($incident->escalation_acknowledged_at !== null) {
            $entries[] = [
                'at' => $incident->escalation_acknowledged_at->toIso8601String(),
                'kind' => 'acknowledged',
                'label' => 'Escalation acknowledged',
                'body' => null,
                'who' => $incident->escalation_acknowledged_by === null ? null : $names->get($incident->escalation_acknowledged_by),
                'classification' => null,
            ];
        }

        foreach ($evidence as $item) {
            $entries[] = [
                'at' => (string) $item->created_at?->toIso8601String(),
                'kind' => 'evidence',
                'label' => "{$item->evidence_type->label()} added",
                'body' => $item->title,
                'who' => $names->get($item->uploaded_by),
                'classification' => ['value' => $item->classificationLevel()->value, 'label' => $item->classificationLevel()->label()],
            ];
        }

        // Consolidations into this incident (Phase 10B).
        $consolidations = IncidentConsolidation::query()->where('original_incident_id', $incident->id)->orderBy('consolidated_at')->get();
        $consolidators = OfficerNames::for($consolidations->map(fn (IncidentConsolidation $c) => $c->consolidated_by));
        foreach ($consolidations as $consolidation) {
            $entries[] = [
                'at' => $consolidation->consolidated_at->toIso8601String(),
                'kind' => 'consolidated',
                'label' => 'Content consolidated from '.DB::table('incidents')->where('id', $consolidation->duplicate_incident_id)->value('reference_no'),
                'body' => null,
                'who' => $consolidators->get($consolidation->consolidated_by),
                'classification' => null,
            ];
        }

        $entries = array_map(fn (array $entry) => ['origin' => null, ...$entry], $entries);
        usort($entries, fn (array $a, array $b) => strcmp($a['at'], $b['at']));

        return $entries;
    }
}
