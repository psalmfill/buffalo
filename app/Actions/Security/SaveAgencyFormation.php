<?php

namespace App\Actions\Security;

use App\Models\AgencyFormation;
use App\Models\Lga;
use App\Models\SecurityAgency;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates an agency formation from validated values (Phase 4A).
 * The agency and LGA are fixed once created. Location changes are audited
 * as a named event with coordinates.
 */
final class SaveAgencyFormation
{
    /**
     * @param  array<string, mixed>  $data  AgencyFormationRequest::values()
     */
    public function create(SecurityAgency $agency, Lga $lga, array $data, User $actor): AgencyFormation
    {
        return DB::transaction(function () use ($agency, $lga, $data, $actor): AgencyFormation {
            $formation = new AgencyFormation;
            $formation->forceFill([
                ...self::attributes($data),
                'security_agency_id' => $agency->id,
                'lga_id' => $lga->id,
                'state_id' => $lga->state_id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->logLocation($formation, null, self::coordinates($data), $actor);

            return $formation;
        });
    }

    /**
     * @param  array<string, mixed>  $data  AgencyFormationRequest::values()
     */
    public function update(AgencyFormation $formation, array $data, User $actor): void
    {
        DB::transaction(function () use ($formation, $data, $actor): void {
            $row = AgencyFormation::query()->withCoordinates()->whereKey($formation->id)->first();
            $before = $row?->latitude === null ? null : ['latitude' => round($row->latitude, 6), 'longitude' => round((float) $row->longitude, 6)];
            $after = self::coordinates($data);
            $attributes = self::attributes($data);

            if ($before === $after) {
                unset($attributes['location']);
            }

            $formation->forceFill($attributes);

            if ($formation->isDirty()) {
                $formation->forceFill(['updated_by' => $actor->id])->save();
            }

            $this->logLocation($formation, $before, $after, $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        $coordinates = self::coordinates($data);
        $temporary = (bool) $data['is_temporary'];

        return [
            'community_id' => $data['community_id'],
            'name' => $data['name'],
            'formation_type' => $data['formation_type'],
            'address' => $data['address'],
            'location' => AgencyFormation::pointFrom($coordinates['latitude'] ?? null, $coordinates['longitude'] ?? null),
            'official_phone' => $data['official_phone'],
            'commander_title' => $data['commander_title'],
            'commander_name' => $data['commander_name'],
            'commander_rank' => $data['commander_rank'],
            'personnel_strength' => $data['personnel_strength'],
            'is_temporary' => $temporary,
            'active_from' => $data['active_from'],
            'active_to' => $data['active_to'],
            'status' => $data['status'],
            'classification' => $data['classification'],
            'notes' => $data['notes'],
            ...(array_key_exists('parent_formation_id', $data) ? ['parent_formation_id' => $data['parent_formation_id']] : []),
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
    private function logLocation(AgencyFormation $formation, ?array $before, ?array $after, User $actor): void
    {
        if ($before !== $after) {
            AuditLog::event('location_changed', "Location of formation {$formation->name} changed", $formation, ['old' => $before, 'new' => $after], $actor);
        }
    }
}
