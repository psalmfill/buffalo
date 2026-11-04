<?php

namespace App\Actions\PersonsOfInterest;

use App\Models\PersonOfInterest;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Edits a POI's details (offences, modus operandi, last known location and
 * sighting, and the personal details of Phase 11G.4). Status and risk change only through ChangePoiStatus. A new
 * last known location is logged as a named event with coordinates.
 */
final class SavePoiDetails
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(PersonOfInterest $poi, array $data, User $actor): void
    {
        DB::transaction(function () use ($poi, $data, $actor): void {
            $before = self::currentCoordinates($poi);
            $after = self::coordinates($data);
            $attributes = self::attributes($data);

            if ($before === $after) {
                unset($attributes['last_known_location']);
            }

            $poi->forceFill($attributes);

            if ($poi->isDirty()) {
                $poi->forceFill(['updated_by' => $actor->id])->save();
            }

            $this->logSighting($poi, $before, $after, $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributes(array $data): array
    {
        $coordinates = self::coordinates($data);

        // Personal details (Phase 11G.4): set when the form sends them.
        $details = [];

        foreach (PersonOfInterest::DETAILS as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $details[$field] = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
            }
        }

        return [
            ...$details,
            'offence_categories' => array_values($data['offence_categories'] ?? []),
            'known_offences' => trim((string) $data['known_offences']),
            'modus_operandi' => $data['modus_operandi'] ?? null,
            'last_known_location' => PersonOfInterest::pointFrom($coordinates['latitude'] ?? null, $coordinates['longitude'] ?? null),
            'last_known_location_description' => $data['last_known_location_description'] ?? null,
            'last_sighted_at' => $data['last_sighted_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{latitude: float, longitude: float}|null
     */
    public static function coordinates(array $data): ?array
    {
        if (! isset($data['latitude'], $data['longitude'])) {
            return null;
        }

        return ['latitude' => round((float) $data['latitude'], 6), 'longitude' => round((float) $data['longitude'], 6)];
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    public static function currentCoordinates(PersonOfInterest $poi): ?array
    {
        $row = PersonOfInterest::query()->withCoordinates()->whereKey($poi->id)->first();

        if ($row?->latitude === null || $row->longitude === null) {
            return null;
        }

        return ['latitude' => round($row->latitude, 6), 'longitude' => round($row->longitude, 6)];
    }

    /**
     * @param  array{latitude: float, longitude: float}|null  $before
     * @param  array{latitude: float, longitude: float}|null  $after
     */
    public function logSighting(PersonOfInterest $poi, ?array $before, ?array $after, User $actor): void
    {
        if ($before === $after) {
            return;
        }

        AuditLog::event('last_known_location_changed', 'Last known location changed', $poi, ['old' => $before, 'new' => $after], $actor);
    }
}
