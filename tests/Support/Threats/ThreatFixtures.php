<?php

namespace Tests\Support\Threats;

use App\Enums\Classification;
use App\Enums\IncidentCategory;
use App\Models\Lga;
use App\Models\ThreatReport;
use App\Models\ThreatType;
use App\Models\User;
use App\Notifications\ThreatNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/**
 * Threat reports for the Phase 6B tests. The test world has no LGA
 * boundaries, so any point in Nigeria is accepted. Centre 6.50 N, 3.40 E.
 */
final class ThreatFixtures
{
    public const LAT = 6.50;

    public const LNG = 3.40;

    public static function crimeType(): ThreatType
    {
        return ThreatType::factory()->create(['name' => 'Attack on a market', 'category' => IncidentCategory::Crime]);
    }

    /**
     * Form values for POST /threats (local times, "Y-m-dTH:i").
     *
     * @return array<string, mixed>
     */
    public static function payload(Lga $lga, ThreatType $type, array $overrides = []): array
    {
        return [
            'lga' => $lga->ulid,
            'threat_type' => $type->ulid,
            'title' => 'Planned attack on the weekly market',
            'description' => 'Informants report armed men planning to attack the market on Saturday.',
            'credibility' => 'probable',
            'urgency' => 'immediate',
            'source_type' => 'informant',
            'source_notes' => 'Two independent informants.',
            'target_description' => 'Weekly cattle market',
            'expected_from' => CarbonImmutable::now()->addDays(2)->format('Y-m-d\TH:i'),
            'expected_until' => CarbonImmutable::now()->addDays(3)->format('Y-m-d\TH:i'),
            'classification' => 'restricted',
            ...$overrides,
        ];
    }

    public static function threat(Lga $lga, array $attributes = [], ?Classification $level = null): ThreatReport
    {
        CrossFixtures::system();

        return ThreatReport::factory()->in($lga)->state([
            'classification' => $level ?? Classification::Restricted,
            ...$attributes,
        ])->create();
    }

    /**
     * World users who received a ThreatNotification for $event.
     *
     * @return list<string>
     */
    public static function notified(string $event, JurisdictionWorld $world): array
    {
        $keys = [];

        foreach ($world->users as $key => $user) {
            /** @var User $user */
            if (Notification::sent($user, ThreatNotification::class, fn (ThreatNotification $n) => $n->event === $event)->isNotEmpty()) {
                $keys[] = $key;
            }
        }
        sort($keys);

        return $keys;
    }
}
