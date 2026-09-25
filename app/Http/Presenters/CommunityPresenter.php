<?php

namespace App\Http\Presenters;

use App\Enums\CommunityType;
use App\Models\Community;

/**
 * Communities for the browser: ULIDs and labels, never ids. Coordinates
 * are present only when loaded with Community::withCoordinates().
 */
final class CommunityPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(Community $community): array
    {
        return [
            'ulid' => $community->ulid,
            'name' => $community->name,
            'other_names' => $community->other_names,
            'type' => ['value' => $community->type->value, 'label' => $community->type->label()],
            'lga' => ['ulid' => $community->lga->ulid, 'name' => $community->lga->name],
            'state' => $community->state->name,
            'ward' => $community->ward?->name,
            'population_estimate' => $community->population_estimate,
            'has_location' => $community->latitude !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Community $community): array
    {
        return [
            ...self::row($community),
            'ward_ulid' => $community->ward?->ulid,
            'latitude' => $community->latitude,
            'longitude' => $community->longitude,
            'notes' => $community->notes,
            'created_at' => $community->created_at?->toIso8601String(),
            'updated_at' => $community->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A map marker (communities with a location only).
     *
     * @return array{id: string, position: array{0: float, 1: float}, title: string, popup: string, href: string}|null
     */
    public static function marker(Community $community): ?array
    {
        if ($community->latitude === null || $community->longitude === null) {
            return null;
        }

        return [
            'id' => $community->ulid,
            'position' => [$community->latitude, $community->longitude],
            'title' => $community->name,
            'popup' => $community->type->label().($community->ward !== null ? ' · '.$community->ward->name : ''),
            'href' => '/communities/'.$community->ulid,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function typeOptions(): array
    {
        return array_map(fn (CommunityType $type): array => ['value' => $type->value, 'label' => $type->label()], CommunityType::cases());
    }
}
