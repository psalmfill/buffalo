<?php

namespace App\Actions\Political;

use App\Enums\PersonRoleType;
use App\Models\Person;
use App\Models\PoliticalOffice;
use App\Models\User;
use App\Support\Persons\RoleAssigner;
use Illuminate\Support\Facades\DB;

/**
 * Records or edits a political office. Recording one makes sure the person
 * holds the political_leader role. Overlapping current holders of a
 * single-seat office are reported back as warnings, never refused: records
 * may be imperfect (OfficeOverlaps).
 */
final class SavePoliticalOffice
{
    public function __construct(
        private readonly RoleAssigner $roles,
        private readonly OfficeOverlaps $overlaps,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated values with ids resolved
     * @return array{office: PoliticalOffice, warnings: list<string>}
     */
    public function create(Person $person, array $data, User $actor): array
    {
        return DB::transaction(function () use ($person, $data, $actor): array {
            $office = new PoliticalOffice;
            $office->forceFill([
                ...self::attributes($data),
                'person_id' => $person->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->roles->activate($person, PersonRoleType::PoliticalLeader, $actor);

            return ['office' => $office, 'warnings' => $this->overlaps->warnings($office)];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{office: PoliticalOffice, warnings: list<string>}
     */
    public function update(PoliticalOffice $office, array $data, User $actor): array
    {
        return DB::transaction(function () use ($office, $data, $actor): array {
            $office->forceFill(self::attributes($data));

            if ($office->isDirty()) {
                $office->forceFill(['updated_by' => $actor->id])->save();
            }

            return ['office' => $office, 'warnings' => $this->overlaps->warnings($office)];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        return [
            'office' => $data['office'],
            'office_title' => $data['office_title'] ?? null,
            'party' => $data['party'] ?? null,
            'state_id' => $data['state_id'],
            'senatorial_district_id' => $data['senatorial_district_id'] ?? null,
            'lga_id' => $data['lga_id'] ?? null,
            'ward_id' => $data['ward_id'] ?? null,
            'constituency_name' => $data['constituency_name'] ?? null,
            'started_on' => $data['started_on'],
            'ended_on' => $data['ended_on'] ?? null,
        ];
    }
}
