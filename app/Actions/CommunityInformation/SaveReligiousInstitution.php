<?php

namespace App\Actions\CommunityInformation;

use App\Models\Community;
use App\Models\Lga;
use App\Models\ReligiousInstitution;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Persons\LinkedPerson;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a religious institution from validated values. The
 * LGA is fixed; the community may change within the LGA. Location changes
 * are audited as a named event with coordinates.
 */
final class SaveReligiousInstitution
{
    /**
     * @param  array{community: Community, religion_id: int, denomination_id: int|null, name: string, type: string, estimated_congregation: int|null, latitude: float|null, longitude: float|null, notes: string|null, leader_person_id?: int|null}  $data
     */
    public function create(Lga $lga, array $data, User $actor): ReligiousInstitution
    {
        return DB::transaction(function () use ($lga, $data, $actor): ReligiousInstitution {
            $institution = new ReligiousInstitution;
            $institution->forceFill([
                ...self::attributes($data),
                'lga_id' => $lga->id,
                'state_id' => $lga->state_id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->logLocation($institution, null, self::coordinates($data), $actor);
            LinkedPerson::logChange($institution, 'leader_changed', "Leader of {$institution->name} set", null, $institution->leader_person_id, $actor);

            return $institution;
        });
    }

    /**
     * @param  array{community: Community, religion_id: int, denomination_id: int|null, name: string, type: string, estimated_congregation: int|null, latitude: float|null, longitude: float|null, notes: string|null, leader_person_id?: int|null}  $data
     */
    public function update(ReligiousInstitution $institution, array $data, User $actor): void
    {
        DB::transaction(function () use ($institution, $data, $actor): void {
            $row = ReligiousInstitution::query()->withCoordinates()->whereKey($institution->id)->first();
            $before = $row?->latitude === null ? null : ['latitude' => round($row->latitude, 6), 'longitude' => round((float) $row->longitude, 6)];
            $after = self::coordinates($data);
            $leaderBefore = $institution->leader_person_id;

            $attributes = self::attributes($data);
            if ($before === $after) {
                unset($attributes['location']);
            }

            $institution->forceFill($attributes);

            if ($institution->isDirty()) {
                $institution->forceFill(['updated_by' => $actor->id])->save();
            }

            $this->logLocation($institution, $before, $after, $actor);
            LinkedPerson::logChange($institution, 'leader_changed', "Leader of {$institution->name} changed", $leaderBefore, $institution->leader_person_id, $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        $coordinates = self::coordinates($data);
        /** @var Community $community */
        $community = $data['community'];

        return [
            'community_id' => $community->id,
            'religion_id' => $data['religion_id'],
            'denomination_id' => $data['denomination_id'],
            'name' => trim((string) $data['name']),
            'type' => $data['type'],
            'estimated_congregation' => $data['estimated_congregation'],
            'notes' => $data['notes'],
            'location' => ReligiousInstitution::pointFrom($coordinates['latitude'] ?? null, $coordinates['longitude'] ?? null),
            // Absent: unchanged.
            ...(array_key_exists('leader_person_id', $data) ? ['leader_person_id' => $data['leader_person_id']] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{latitude: float, longitude: float}|null
     */
    private static function coordinates(array $data): ?array
    {
        return isset($data['latitude'], $data['longitude'])
            ? ['latitude' => round((float) $data['latitude'], 6), 'longitude' => round((float) $data['longitude'], 6)]
            : null;
    }

    /**
     * @param  array{latitude: float, longitude: float}|null  $before
     * @param  array{latitude: float, longitude: float}|null  $after
     */
    private function logLocation(ReligiousInstitution $institution, ?array $before, ?array $after, User $actor): void
    {
        if ($before !== $after) {
            AuditLog::event('location_changed', "Location of religious institution {$institution->name} changed", $institution, ['old' => $before, 'new' => $after], $actor);
        }
    }
}
