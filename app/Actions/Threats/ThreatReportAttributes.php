<?php

namespace App\Actions\Threats;

use App\Models\Flashpoint;
use App\Models\ThreatReport;

/**
 * The plainly editable columns of a threat report, from validated values
 * (ThreatReportRequest::values()). Credibility, urgency, status and expiry
 * are set by the actions that log them.
 */
final class ThreatReportAttributes
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function from(array $data): array
    {
        return [
            'threat_type_id' => $data['threat_type_id'],
            'title' => $data['title'],
            'description' => $data['description'],
            'source_type' => $data['source_type'],
            'source_notes' => $data['source_notes'],
            'community_id' => $data['community_id'],
            'location' => isset($data['latitude'], $data['longitude'])
                ? Flashpoint::pointFrom((float) $data['latitude'], (float) $data['longitude'])
                : null,
            'radius_meters' => $data['radius_meters'],
            'target_description' => $data['target_description'],
            'expected_from' => $data['expected_from'],
            'expected_until' => $data['expected_until'],
            'recommended_action' => $data['recommended_action'],
            'classification' => $data['classification'],
        ];
    }

    /**
     * Current coordinates, rounded, for change detection and the audit event.
     *
     * @return array{latitude: float, longitude: float, radius_meters: int|null}|null
     */
    public static function area(ThreatReport $threat): ?array
    {
        $row = ThreatReport::query()->withCoordinates()->whereKey($threat->id)->first();

        return $row?->latitude === null ? null : [
            'latitude' => round($row->latitude, 6),
            'longitude' => round((float) $row->longitude, 6),
            'radius_meters' => $row->radius_meters,
        ];
    }
}
