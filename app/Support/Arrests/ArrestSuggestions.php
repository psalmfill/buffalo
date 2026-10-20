<?php

namespace App\Support\Arrests;

use App\Enums\PoiStatus;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\User;

/**
 * What to suggest about an arrested person (Phase 11C), never applied
 * automatically: a person of interest the viewer may manage who is still
 * at large or under surveillance → "update their status to detained"; a
 * person who is not a POI → "designate as person of interest" (when the
 * viewer may).
 */
final class ArrestSuggestions
{
    /**
     * @return array<string, string>|null
     */
    public static function for(Person $person, User $viewer): ?array
    {
        $poi = PersonOfInterest::query()->where('person_id', $person->id)->first();

        return match (true) {
            $poi !== null && in_array($poi->status, [PoiStatus::AtLarge, PoiStatus::UnderSurveillance], true) && $viewer->can('update', $poi) => ['kind' => 'poi_detained', 'poi' => $poi->ulid, 'person' => $person->ulid, 'current' => $poi->status->label()],
            $poi === null && $viewer->can('create', [PersonOfInterest::class, $person]) => ['kind' => 'designate_poi', 'person' => $person->ulid],
            default => null,
        };
    }
}
