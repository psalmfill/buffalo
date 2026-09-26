<?php

namespace App\Actions\LocalGovernment;

use App\Models\Community;
use App\Models\Lga;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Persons\LinkedPerson;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a community from validated values. The LGA (and so the
 * state) is fixed once created: moving a community would change who may
 * see it. Location changes are audited as a named event with coordinates
 * (the geography column itself is excluded from the automatic log).
 */
final class SaveCommunity
{
    /**
     * @param  array{name: string, other_names?: list<string>, type: string, ward_id?: int|null, latitude?: float|null, longitude?: float|null, population_estimate?: int|null, notes?: string|null, traditional_ruler_id?: int|null}  $data
     */
    public function create(Lga $lga, array $data, User $actor): Community
    {
        return DB::transaction(function () use ($lga, $data, $actor): Community {
            $community = new Community;
            $community->forceFill([
                ...self::attributes($data),
                'lga_id' => $lga->id,
                'state_id' => $lga->state_id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->logLocation($community, null, self::coordinates($data), $actor);
            LinkedPerson::logChange($community, 'traditional_ruler_changed', "Traditional ruler of {$community->name} set", null, $community->traditional_ruler_id, $actor);

            return $community;
        });
    }

    /**
     * @param  array{name: string, other_names?: list<string>, type: string, ward_id?: int|null, latitude?: float|null, longitude?: float|null, population_estimate?: int|null, notes?: string|null, traditional_ruler_id?: int|null}  $data
     */
    public function update(Community $community, array $data, User $actor): Community
    {
        return DB::transaction(function () use ($community, $data, $actor): Community {
            $before = self::currentCoordinates($community);
            $after = self::coordinates($data);
            $rulerBefore = $community->traditional_ruler_id;

            $attributes = self::attributes($data);

            if ($before === $after) {
                unset($attributes['location']);
            }

            $community->forceFill($attributes);

            if ($community->isDirty()) {
                $community->forceFill(['updated_by' => $actor->id])->save();
            }

            if ($before !== $after) {
                $this->logLocation($community, $before, $after, $actor);
            }

            LinkedPerson::logChange($community, 'traditional_ruler_changed', "Traditional ruler of {$community->name} changed", $rulerBefore, $community->traditional_ruler_id, $actor);

            return $community;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        $coordinates = self::coordinates($data);

        return [
            'name' => trim((string) $data['name']),
            'other_names' => array_values($data['other_names'] ?? []),
            'type' => $data['type'],
            'ward_id' => $data['ward_id'] ?? null,
            'population_estimate' => $data['population_estimate'] ?? null,
            'notes' => $data['notes'] ?? null,
            'location' => Community::pointFrom($coordinates['latitude'] ?? null, $coordinates['longitude'] ?? null),
            // Absent: unchanged.
            ...(array_key_exists('traditional_ruler_id', $data) ? ['traditional_ruler_id' => $data['traditional_ruler_id']] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{latitude: float, longitude: float}|null
     */
    private static function coordinates(array $data): ?array
    {
        if (! isset($data['latitude'], $data['longitude'])) {
            return null;
        }

        return ['latitude' => round((float) $data['latitude'], 6), 'longitude' => round((float) $data['longitude'], 6)];
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    public static function currentCoordinates(Community $community): ?array
    {
        $row = Community::query()->whereKey($community->id)->withCoordinates()->first();

        if ($row?->latitude === null || $row->longitude === null) {
            return null;
        }

        return ['latitude' => round($row->latitude, 6), 'longitude' => round($row->longitude, 6)];
    }

    /**
     * @param  array{latitude: float, longitude: float}|null  $before
     * @param  array{latitude: float, longitude: float}|null  $after
     */
    private function logLocation(Community $community, ?array $before, ?array $after, User $actor): void
    {
        if ($before === $after) {
            return;
        }

        AuditLog::event('location_changed', "Location of community {$community->name} changed", $community, [
            'old' => $before,
            'new' => $after,
        ], $actor);
    }
}
