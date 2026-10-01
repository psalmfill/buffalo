<?php

namespace App\Actions\Flashpoints;

use App\Models\Flashpoint;
use App\Models\Lga;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits a flashpoint (Phase 6A).
 *
 * Creating one records its first assessment (AssessFlashpoint), which sets
 * the concern level, status and review date. Editing changes only the
 * descriptive fields, the place and area and the classification; the
 * assessed fields change only through a new assessment. Moving the LGA
 * carries assessments and links along (cascading composite FKs). Location
 * and radius changes are a named event with the values.
 */
final class SaveFlashpoint
{
    public function __construct(private readonly AssessFlashpoint $assess) {}

    /**
     * @param  array<string, mixed>  $data  FlashpointRequest::values()
     * @param  array{concern_level: string, status: string, assessment: string, recommended_action: string|null}  $assessment
     */
    public function create(Lga $lga, array $data, array $assessment, User $actor): Flashpoint
    {
        return DB::transaction(function () use ($lga, $data, $assessment, $actor): Flashpoint {
            $flashpoint = new Flashpoint;
            $flashpoint->forceFill([
                ...self::attributes($data),
                'lga_id' => $lga->id,
                'state_id' => $lga->state_id,
                // Set properly by the first assessment below.
                'concern_level' => $assessment['concern_level'],
                'status' => $assessment['status'],
                'last_assessed_at' => now(),
                'review_due_at' => today(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            return $this->assess->record($flashpoint, $assessment, $actor, first: true);
        });
    }

    /**
     * @param  array<string, mixed>  $data  FlashpointRequest::values()
     */
    public function update(Flashpoint $flashpoint, ?Lga $lga, array $data, User $actor): void
    {
        DB::transaction(function () use ($flashpoint, $lga, $data, $actor): void {
            $row = Flashpoint::query()->withCoordinates()->whereKey($flashpoint->id)->lockForUpdate()->firstOrFail();
            $before = ['latitude' => round((float) $row->latitude, 6), 'longitude' => round((float) $row->longitude, 6), 'radius_meters' => $row->radius_meters];
            $after = ['latitude' => round((float) $data['latitude'], 6), 'longitude' => round((float) $data['longitude'], 6), 'radius_meters' => (int) $data['radius_meters']];
            $attributes = self::attributes($data);

            if ($before['latitude'] === $after['latitude'] && $before['longitude'] === $after['longitude']) {
                unset($attributes['location']);
            }

            if ($lga !== null && $lga->id !== $flashpoint->lga_id) {
                $attributes['lga_id'] = $lga->id;
                $attributes['state_id'] = $lga->state_id;
            }

            $flashpoint->forceFill($attributes);

            if ($flashpoint->isDirty()) {
                $flashpoint->forceFill(['updated_by' => $actor->id])->save();
            }

            if ($before !== $after) {
                AuditLog::event('area_changed', 'Flashpoint area changed', $flashpoint, ['old' => $before, 'new' => $after], $actor);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        return [
            'kind' => $data['kind'],
            'name' => $data['name'],
            'threat_type_id' => $data['threat_type_id'],
            'community_id' => $data['community_id'],
            'location' => Flashpoint::pointFrom((float) $data['latitude'], (float) $data['longitude']),
            'radius_meters' => (int) $data['radius_meters'],
            'description' => $data['description'],
            'classification' => $data['classification'],
        ];
    }
}
