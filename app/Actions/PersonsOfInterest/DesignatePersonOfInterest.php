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
 * Designates a person as a person of interest: one POI record per person,
 * placed where the person is, due for review in BUFFALO_POI_REVIEW_MONTHS
 * months, and the person_of_interest role activated.
 */
final class DesignatePersonOfInterest
{
    public function __construct(
        private readonly RoleAssigner $roles,
        private readonly SavePoiDetails $details,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated details, plus risk_level and status
     */
    public function __invoke(Person $person, array $data, string $comment, User $actor): PersonOfInterest
    {
        return DB::transaction(function () use ($person, $data, $comment, $actor): PersonOfInterest {
            $poi = new PersonOfInterest;
            $poi->forceFill([
                ...SavePoiDetails::attributes($data),
                'person_id' => $person->id,
                'state_id' => $person->state_id,
                'lga_id' => $person->lga_id,
                'risk_level' => RiskLevel::from((string) $data['risk_level']),
                'status' => PoiStatus::from((string) $data['status']),
                'review_due_at' => self::nextReview(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->details->logSighting($poi, null, SavePoiDetails::coordinates($data), $actor);
            $this->roles->activate($person, PersonRoleType::PersonOfInterest, $actor);

            AuditLog::event('poi_designated', 'Designated as a person of interest', $poi, [
                'risk_level' => $poi->risk_level->value,
                'status' => $poi->status->value,
                'comment' => $comment,
            ], $actor);

            return $poi;
        });
    }

    public static function nextReview(): string
    {
        return CarbonImmutable::today()->addMonthsNoOverflow((int) config('buffalo.poi_review_months', 12))->toDateString();
    }
}
