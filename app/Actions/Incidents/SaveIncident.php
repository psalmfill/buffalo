<?php

namespace App\Actions\Incidents;

use App\Models\Incident;
use App\Models\IncidentAgency;
use App\Models\IncidentCasualty;
use App\Models\IncidentChild;
use App\Models\IncidentCriminalGroup;
use App\Models\IncidentPerson;
use App\Models\IncidentProperty;
use App\Models\Lga;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates an incident draft (Phase 5A) with the full lists of
 * its related rows: rows with an id are updated, new rows created, and rows
 * left out soft-deleted. Child rows take the incident's state_id/lga_id;
 * when the incident moves LGA, the database cascades the change to them.
 * Location changes are a named event with coordinates.
 */
final class SaveIncident
{
    /** @var array<string, class-string<IncidentChild>> */
    private const CHILDREN = [
        'casualties' => IncidentCasualty::class,
        'persons' => IncidentPerson::class,
        'groups' => IncidentCriminalGroup::class,
        'agencies' => IncidentAgency::class,
        'properties' => IncidentProperty::class,
    ];

    /**
     * @param  array<string, mixed>  $data  IncidentRequest::values()
     */
    public function create(Lga $lga, array $data, User $actor): Incident
    {
        return DB::transaction(function () use ($lga, $data, $actor): Incident {
            $incident = new Incident;
            $incident->forceFill([
                ...self::header($data),
                'lga_id' => $lga->id,
                'state_id' => $lga->state_id,
                'reporting_officer_id' => $actor->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->logLocation($incident, null, self::coordinates($data), $actor);
            $this->syncChildren($incident, $data, $actor);

            return $incident;
        });
    }

    /**
     * @param  array<string, mixed>  $data  IncidentRequest::values()
     */
    public function update(Incident $incident, Lga $lga, array $data, User $actor): void
    {
        DB::transaction(function () use ($incident, $lga, $data, $actor): void {
            $row = Incident::query()->withCoordinates()->whereKey($incident->id)->first();
            $before = $row?->latitude === null ? null : ['latitude' => round($row->latitude, 6), 'longitude' => round((float) $row->longitude, 6)];
            $after = self::coordinates($data);
            $attributes = [...self::header($data), 'lga_id' => $lga->id, 'state_id' => $lga->state_id];

            if ($before === $after) {
                unset($attributes['location']);
            }

            $incident->forceFill($attributes);

            if ($incident->isDirty()) {
                $incident->forceFill(['updated_by' => $actor->id])->save();
            }

            $this->logLocation($incident, $before, $after, $actor);
            $this->syncChildren($incident, $data, $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function header(array $data): array
    {
        $coordinates = self::coordinates($data);

        return [
            'incident_type_id' => $data['incident_type_id'],
            'details' => $data['details'],
            'details_schema_version' => $data['details_schema_version'],
            'title' => $data['title'],
            'description' => $data['description'],
            'severity' => $data['severity'],
            'occurred_at' => $data['occurred_at'],
            'occurred_at_precision' => $data['occurred_at_precision'],
            'community_id' => $data['community_id'],
            'location' => Incident::pointFrom($coordinates['latitude'] ?? null, $coordinates['longitude'] ?? null),
            'location_description' => $data['location_description'],
            'action_taken' => $data['action_taken'],
            'classification' => $data['classification'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncChildren(Incident $incident, array $data, User $actor): void
    {
        foreach (self::CHILDREN as $key => $class) {
            $keep = [];

            /** @var list<array<string, mixed>> $rows */
            $rows = $data[$key];

            foreach ($rows as $row) {
                $id = $row['id'];
                unset($row['id']);

                $child = $id === null
                    ? new $class
                    : $class::query()->where('incident_id', $incident->id)->whereKey($id)->firstOrFail();

                // A changed agency drops a formation of the old agency.
                if ($child instanceof IncidentAgency && $child->exists && ! array_key_exists('agency_formation_id', $row) && $child->security_agency_id !== $row['security_agency_id']) {
                    $row['agency_formation_id'] = null;
                }

                $child->forceFill($row);

                if (! $child->exists) {
                    $child->forceFill([
                        'incident_id' => $incident->id,
                        'state_id' => $incident->state_id,
                        'lga_id' => $incident->lga_id,
                        'created_by' => $actor->id,
                    ]);
                }

                if ($child->isDirty()) {
                    $child->forceFill(['updated_by' => $actor->id])->save();
                }

                $keep[] = $child->id;
            }

            $class::query()->where('incident_id', $incident->id)->whereNotIn('id', $keep)->get()
                ->each(fn (IncidentChild $removed) => $removed->forceFill(['updated_by' => $actor->id])->delete());
        }
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
    private function logLocation(Incident $incident, ?array $before, ?array $after, User $actor): void
    {
        if ($before !== $after) {
            AuditLog::event('location_changed', 'Location of the incident changed', $incident, ['old' => $before, 'new' => $after], $actor);
        }
    }
}
