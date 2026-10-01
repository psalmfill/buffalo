<?php

namespace App\Actions\Flashpoints;

use App\Enums\ConcernLevel;
use App\Enums\FlashpointStatus;
use App\Models\Flashpoint;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records an assessment of a flashpoint (Phase 6A) and makes it current, in
 * one transaction: the append-only assessment row, then the flashpoint's
 * concern level, status, recommended action, last assessment and next
 * review date (today + the concern level's review days). Logged as the
 * named event "assessed" with the before and after values.
 */
final class AssessFlashpoint
{
    /**
     * @param  array{concern_level: string, status: string, assessment: string, recommended_action: string|null}  $data
     */
    public function __invoke(Flashpoint $flashpoint, array $data, User $actor): Flashpoint
    {
        return DB::transaction(function () use ($flashpoint, $data, $actor): Flashpoint {
            $locked = Flashpoint::query()->whereKey($flashpoint->id)->lockForUpdate()->firstOrFail();

            return $this->record($locked, $data, $actor, first: false);
        });
    }

    /**
     * Inside an open transaction, with the flashpoint locked or just created.
     *
     * @param  array{concern_level: string, status: string, assessment: string, recommended_action: string|null}  $data
     */
    public function record(Flashpoint $flashpoint, array $data, User $actor, bool $first): Flashpoint
    {
        $now = CarbonImmutable::now();
        $concern = ConcernLevel::from($data['concern_level']);
        $status = FlashpointStatus::from($data['status']);
        $before = $first ? null : [
            'concern_level' => $flashpoint->concern_level->value,
            'status' => $flashpoint->status->value,
            'review_due_at' => $flashpoint->review_due_at->toDateString(),
        ];

        DB::table('flashpoint_assessments')->insert([
            'ulid' => Str::lower((string) Str::ulid()),
            'flashpoint_id' => $flashpoint->id,
            'state_id' => $flashpoint->state_id,
            'lga_id' => $flashpoint->lga_id,
            'concern_level' => $concern->value,
            'status' => $status->value,
            'assessment' => $data['assessment'],
            'recommended_action' => $data['recommended_action'],
            'assessed_by' => $actor->id,
            'assessed_at' => $now,
        ]);

        $due = CarbonImmutable::today()->addDays($concern->reviewDays());

        $flashpoint->forceFill([
            'concern_level' => $concern,
            'status' => $status,
            'recommended_action' => $data['recommended_action'],
            'last_assessed_at' => $now,
            'last_assessed_by' => $actor->id,
            'review_due_at' => $due,
            'review_reminded_for' => null,
            'updated_by' => $actor->id,
        ]);

        // The named event below records the change; no generic "updated" entry.
        $flashpoint->saveQuietly();

        AuditLog::event('assessed', $first ? 'First assessment recorded' : 'Flashpoint reassessed', $flashpoint, [
            'old' => $before,
            'new' => ['concern_level' => $concern->value, 'status' => $status->value, 'review_due_at' => $due->toDateString()],
        ], $actor);

        return $flashpoint;
    }
}
