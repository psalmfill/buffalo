<?php

namespace Tests\Support\Flashpoints;

use App\Enums\Classification;
use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Lga;
use App\Models\ThreatType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;

/**
 * Flashpoints and incidents placed on the map (Phase 6A tests). The test
 * world has no LGA boundaries, so any point in Nigeria is accepted.
 * Centre: 6.50 N, 3.40 E; 0.01 degrees of latitude is about 1.1 km.
 */
final class FlashpointFixtures
{
    public const LAT = 6.50;

    public const LNG = 3.40;

    public static function payload(Lga $lga, ThreatType $threat, array $overrides = []): array
    {
        return [
            'lga' => $lga->ulid,
            'kind' => 'blackspot',
            'name' => 'Toll gate kidnapping corridor',
            'threat_type' => $threat->ulid,
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'radius_meters' => 5000,
            'description' => 'Travellers abducted repeatedly along the corridor.',
            'classification' => 'restricted',
            'concern_level' => 'high',
            'status' => 'active',
            'assessment' => 'Three abductions in two months; gangs move at dusk.',
            'recommended_action' => 'Joint patrols at dusk.',
            ...$overrides,
        ];
    }

    public static function flashpoint(Lga $lga, array $attributes = [], ?Classification $level = null): Flashpoint
    {
        CrossFixtures::system();

        return Flashpoint::factory()->in($lga)->at(self::LAT, self::LNG, 5000)
            ->state(['classification' => $level ?? Classification::Restricted, ...$attributes])
            ->create();
    }

    /** An incident at an offset (degrees) from the centre, in a given status. */
    public static function incident(Lga $lga, User $creator, float $dLat = 0.0, IncidentStatus $status = IncidentStatus::Verified, array $attributes = []): Incident
    {
        $incident = W::draft($lga, $creator, [
            'location' => Flashpoint::pointFrom(self::LAT + $dLat, self::LNG),
            'occurred_at' => CarbonImmutable::now()->subDays(3),
            ...$attributes,
        ]);

        return $status === IncidentStatus::Draft ? $incident : W::at($incident, $status);
    }

    public static function crimeType(): IncidentType
    {
        return IncidentType::factory()->create(['category' => IncidentCategory::Crime]);
    }
}
