<?php

namespace App\Actions\Arrests;

use App\Enums\ArrestStatus;
use App\Models\Arrest;
use App\Models\ArrestPerson;
use App\Models\ArrestStatusHistory;
use App\Models\User;
use App\Support\Arrests\ArrestStatuses;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Changes the status of an arrest or of one arrested person (Phase 11C),
 * always with a comment: status history rows (append-only) and a named
 * activity event.
 *
 * - Arrest-wide: every arrested person takes the new status.
 * - One person: that person's status; the arrest then shows the most
 *   common status, recorded too when it changes.
 */
final class ChangeArrestStatus
{
    public function __invoke(Arrest $arrest, ?ArrestPerson $person, ArrestStatus $to, string $comment, User $actor): void
    {
        $wide = $person === null;
        $personFrom = $person?->status;
        $personUlid = $person?->ulid;

        DB::transaction(function () use ($arrest, $person, $wide, $personFrom, $personUlid, $to, $comment, $actor): void {
            $at = CarbonImmutable::now();
            $locked = Arrest::query()->whereKey($arrest->id)->lockForUpdate()->firstOrFail();
            $people = $person === null
                ? ArrestPerson::query()->where('arrest_id', $locked->id)->get()
                : collect([$person]);

            foreach ($people as $row) {
                if ($row->status === $to) {
                    continue;
                }

                $this->history($locked, $row, $row->status, $to, $comment, $actor, $at);
                $row->forceFill(['status' => $to, 'updated_by' => $actor->id])->save();
            }

            $from = $locked->status;
            $new = $wide ? $to : ArrestStatuses::mostCommon($locked, $from);

            if ($new !== $from) {
                $this->history($locked, null, $from, $new, $comment, $actor, $at);
                $locked->forceFill(['status' => $new, 'updated_by' => $actor->id])->save();
            }

            AuditLog::event('status_changed', $wide ? 'Arrest status changed' : 'Arrested person\'s status changed', $locked, array_filter([
                'person' => $personUlid,
                'from' => $wide ? $from->value : $personFrom->value,
                'to' => $to->value,
                'arrest_status' => $new->value,
                'comment' => $comment,
            ], fn ($v) => $v !== null), $actor);
        });
    }

    private function history(Arrest $arrest, ?ArrestPerson $person, ?ArrestStatus $from, ArrestStatus $to, string $comment, User $actor, CarbonImmutable $at): void
    {
        (new ArrestStatusHistory)->forceFill([
            'arrest_id' => $arrest->id,
            'arrest_person_id' => $person?->id,
            'state_id' => $arrest->state_id,
            'lga_id' => $arrest->lga_id,
            'from_status' => $from,
            'to_status' => $to,
            'comment' => $comment,
            'changed_by' => $actor->id,
            'changed_at' => $at,
        ])->save();
    }
}
