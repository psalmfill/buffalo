<?php

namespace App\Support\Incidents;

use App\Enums\IncidentStatus;
use App\Events\Incidents\IncidentBroadcast;
use App\Events\Incidents\IncidentStatusChanged;
use App\Models\Incident;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Shared steps of every incident status change (Phase 5C): run in a
 * transaction on the locked incident row, write the append-only status
 * history and a named activity event, and broadcast after commit.
 */
final class IncidentWorkflow
{
    /**
     * Moves $incident to $to. $columns are the workflow columns to set;
     * $before may change the incident first (e.g. assign a reference).
     *
     * @param  array<string, mixed>  $columns
     * @param  array<string, mixed>  $properties  extra activity properties
     * @param  class-string<IncidentBroadcast>  $broadcast
     */
    public function move(
        Incident $incident,
        IncidentStatus $to,
        User $actor,
        array $columns,
        ?string $comment,
        string $event,
        string $description,
        array $properties = [],
        string $broadcast = IncidentStatusChanged::class,
        ?Closure $before = null,
    ): Incident {
        return DB::transaction(function () use ($incident, $to, $actor, $columns, $comment, $event, $description, $properties, $broadcast, $before): Incident {
            $locked = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $at = CarbonImmutable::now();

            if ($before !== null) {
                $columns = [...$columns, ...$before($locked, $at)];
            }

            $locked->forceFill([...$columns, 'status' => $to, 'updated_by' => $actor->id])->save();
            $this->history($locked, $from, $to, $actor, $comment, $at);

            AuditLog::event($event, $description, $locked, [
                'from' => $from->value,
                'to' => $to->value,
                ...($comment !== null ? ['comment' => $comment] : []),
                ...$properties,
            ], $actor);

            $broadcast::dispatch($locked, $this->stateUlid($locked));

            return $locked;
        });
    }

    public function history(Incident $incident, ?IncidentStatus $from, IncidentStatus $to, ?User $actor, ?string $comment, CarbonImmutable $at): void
    {
        DB::table('incident_status_histories')->insert([
            'incident_id' => $incident->id,
            'state_id' => $incident->state_id,
            'lga_id' => $incident->lga_id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'comment' => $comment,
            'changed_by' => $actor?->id,
            'changed_at' => $at,
        ]);
    }

    public function stateUlid(Incident $incident): string
    {
        return (string) DB::table('states')->where('id', $incident->state_id)->value('ulid');
    }
}
