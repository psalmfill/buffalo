<?php

namespace App\Http\Presenters;

use App\Enums\CasualtyOutcome;
use App\Models\Incident;
use App\Support\Jurisdiction\OfficerNames;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Incident list rows (Phase 5D), for the list, the record tabs and the
 * dashboards: reference, type, title, place, time, severity, status,
 * escalation, a casualty summary ("3 killed, 2 abducted") and the
 * reporting officer. Casualties and names are loaded in one query each.
 */
final class IncidentRows
{
    /**
     * @param  iterable<Incident>  $incidents  with type, lga and state loaded
     * @return list<array<string, mixed>>
     */
    public static function present(iterable $incidents): array
    {
        $incidents = collect($incidents);

        if ($incidents->isEmpty()) {
            return [];
        }

        $casualties = self::casualties(array_values(array_map('intval', $incidents->pluck('id')->all())));
        $names = OfficerNames::for($incidents->map(fn (Incident $i) => $i->reporting_officer_id));

        return array_values($incidents->map(fn (Incident $incident) => [
            'ulid' => $incident->ulid,
            'reference_no' => $incident->reference_no,
            'title' => $incident->title,
            'type' => ['ulid' => $incident->type->ulid, 'name' => $incident->type->name, 'category' => $incident->type->category->value],
            'lga' => ['ulid' => $incident->lga->ulid, 'name' => $incident->lga->name],
            'state' => ['ulid' => $incident->state->ulid, 'name' => $incident->state->name],
            'occurred_at' => $incident->occurred_at->toIso8601String(),
            'severity' => IncidentPresenter::option($incident->severity),
            'status' => IncidentPresenter::option($incident->status),
            'classification' => IncidentPresenter::option($incident->classificationLevel()),
            'escalation' => $incident->escalated_at === null ? null : ['acknowledged' => $incident->escalation_acknowledged_at !== null],
            'casualties' => $casualties->get($incident->id, ''),
            'reporting_officer' => $names->get($incident->reporting_officer_id),
        ])->all());
    }

    /**
     * "3 killed, 2 abducted" per incident, from live casualty rows.
     *
     * @param  list<int>  $ids
     * @return Collection<int, string>
     */
    public static function casualties(array $ids): Collection
    {
        $order = array_map(fn (CasualtyOutcome $o) => $o->value, CasualtyOutcome::cases());

        return DB::table('incident_casualties')
            ->whereIn('incident_id', $ids)
            ->whereNull('deleted_at')
            ->groupBy('incident_id', 'outcome')
            ->selectRaw('incident_id, outcome, SUM(adult_male + adult_female + child_male + child_female + "unknown") AS total')
            ->get()
            ->groupBy('incident_id')
            ->map(fn (Collection $rows) => $rows
                ->sortBy(fn (object $r) => array_search($r->outcome, $order, true))
                ->filter(fn (object $r) => (int) $r->total > 0)
                ->map(fn (object $r) => ((int) $r->total).' '.strtolower(CasualtyOutcome::from($r->outcome)->label()))
                ->implode(', '));
    }
}
