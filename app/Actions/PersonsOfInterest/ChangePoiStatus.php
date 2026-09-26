<?php

namespace App\Actions\PersonsOfInterest;

use App\Enums\PersonRoleType;
use App\Enums\PoiStatus;
use App\Enums\RiskLevel;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Persons\RoleAssigner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Changes a POI's status and/or risk level, always with a comment. Each
 * change is a named audit event (poi_status_changed, poi_risk_changed)
 * with the old and new values and the comment: the POI's timeline.
 *
 * - cleared: due for review in 30 days (to decide on archiving) and the
 *   person_of_interest role is deactivated; the record and its history
 *   stay. Leaving "cleared" reactivates the role.
 * - deceased: the person is marked deceased too.
 *
 * Whether the actor may clear is checked by the policy (poi.clear).
 */
final class ChangePoiStatus
{
    public function __construct(private readonly RoleAssigner $roles) {}

    public function __invoke(PersonOfInterest $poi, ?PoiStatus $status, ?RiskLevel $risk, string $comment, User $actor): void
    {
        DB::transaction(function () use ($poi, $status, $risk, $comment, $actor): void {
            $oldStatus = $poi->status;
            $oldRisk = $poi->risk_level;
            $person = Person::query()->findOrFail($poi->person_id);

            if ($risk !== null && $risk !== $oldRisk) {
                $poi->risk_level = $risk;
                AuditLog::event('poi_risk_changed', "Risk level changed from {$oldRisk->label()} to {$risk->label()}", $poi, [
                    'old' => $oldRisk->value, 'new' => $risk->value, 'comment' => $comment,
                ], $actor);
            }

            if ($status !== null && $status !== $oldStatus) {
                $poi->status = $status;
                AuditLog::event('poi_status_changed', "Status changed from {$oldStatus->label()} to {$status->label()}", $poi, [
                    'old' => $oldStatus->value, 'new' => $status->value, 'comment' => $comment,
                ], $actor);

                if ($status === PoiStatus::Cleared) {
                    $poi->review_due_at = CarbonImmutable::today()->addDays((int) config('buffalo.poi_cleared_review_days', 30));
                    $this->roles->deactivate($person, PersonRoleType::PersonOfInterest);
                } elseif ($oldStatus === PoiStatus::Cleared) {
                    $this->roles->activate($person, PersonRoleType::PersonOfInterest, $actor);
                }

                if ($status === PoiStatus::Deceased && ! $person->is_deceased) {
                    $person->forceFill(['is_deceased' => true, 'updated_by' => $actor->id])->save();
                }
            }

            if ($poi->isDirty()) {
                $poi->forceFill(['updated_by' => $actor->id])->save();
            }
        });
    }
}
