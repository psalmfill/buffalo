<?php

namespace App\Actions\Evidence;

use App\Enums\Classification;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Edits an evidence item's metadata (Phase 5B): title, description, capture
 * time and place, classification. The file never changes. Field changes
 * are in the activity log; a new capture location is logged as
 * coordinates; a classification change is logged by HasClassification.
 */
final class UpdateEvidence
{
    /**
     * @param  array{title: string, description: string|null, captured_at: CarbonImmutable|null, latitude: float|null, longitude: float|null, classification: Classification}  $data
     */
    public function __invoke(IncidentEvidence $evidence, array $data, User $actor): IncidentEvidence
    {
        return DB::transaction(function () use ($evidence, $data, $actor): IncidentEvidence {
            $before = $this->coordinates($evidence);
            $after = $data['latitude'] === null || $data['longitude'] === null
                ? null
                : ['latitude' => round($data['latitude'], 7), 'longitude' => round($data['longitude'], 7)];

            $evidence->forceFill([
                'title' => $data['title'],
                'description' => $data['description'],
                'captured_at' => $data['captured_at'],
                'classification' => $data['classification'],
                'updated_by' => $actor->id,
            ]);

            if ($before !== $after) {
                $evidence->forceFill(['captured_location' => Incident::pointFrom($after['latitude'] ?? null, $after['longitude'] ?? null)]);
            }

            $evidence->save();

            if ($before !== $after) {
                AuditLog::event('location_changed', 'Capture location changed', $evidence, ['old' => $before, 'new' => $after], $actor);
            }

            return $evidence;
        });
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    private function coordinates(IncidentEvidence $evidence): ?array
    {
        $row = IncidentEvidence::query()->withCoordinates()->whereKey($evidence->id)->first();

        if ($row === null || $row->latitude === null || $row->longitude === null) {
            return null;
        }

        return ['latitude' => round((float) $row->latitude, 7), 'longitude' => round((float) $row->longitude, 7)];
    }
}
