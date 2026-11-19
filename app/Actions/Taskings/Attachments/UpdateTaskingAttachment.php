<?php

namespace App\Actions\Taskings\Attachments;

use App\Enums\Classification;
use App\Models\Incident;
use App\Models\TaskingAttachment;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Edits an attachment's caption, capture time and place, and
 * classification (Phase 11H, as for security report attachments in 11G).
 * The file never changes. A new capture
 * location is logged as coordinates; a classification change is logged by
 * HasClassification.
 */
final class UpdateTaskingAttachment
{
    /**
     * @param  array{caption: string|null, captured_at: CarbonImmutable|null, latitude: float|null, longitude: float|null, classification: Classification}  $data
     */
    public function __invoke(TaskingAttachment $attachment, array $data, User $actor): TaskingAttachment
    {
        return DB::transaction(function () use ($attachment, $data, $actor): TaskingAttachment {
            $before = $this->coordinates($attachment);
            $after = $data['latitude'] === null || $data['longitude'] === null
                ? null
                : ['latitude' => round($data['latitude'], 7), 'longitude' => round($data['longitude'], 7)];

            $attachment->forceFill([
                'caption' => $data['caption'],
                'captured_at' => $data['captured_at'],
                'classification' => $data['classification'],
                'updated_by' => $actor->id,
            ]);

            if ($before !== $after) {
                $attachment->forceFill(['captured_location' => Incident::pointFrom($after['latitude'] ?? null, $after['longitude'] ?? null)]);
            }

            $attachment->save();

            if ($before !== $after) {
                AuditLog::event('location_changed', 'Capture location changed', $attachment, ['old' => $before, 'new' => $after], $actor);
            }

            return $attachment;
        });
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    private function coordinates(TaskingAttachment $attachment): ?array
    {
        $row = TaskingAttachment::query()->withCoordinates()->whereKey($attachment->id)->first();

        if ($row === null || $row->latitude === null || $row->longitude === null) {
            return null;
        }

        return ['latitude' => round((float) $row->latitude, 7), 'longitude' => round((float) $row->longitude, 7)];
    }
}
