<?php

namespace App\Http\Presenters;

use App\Enums\Classification;
use App\Enums\CriminalGroupStatus;
use App\Enums\CriminalGroupType;
use App\Enums\MembershipStatus;
use App\Models\CriminalGroup;
use App\Models\CriminalGroupArea;
use App\Models\CriminalGroupMember;

/**
 * Criminal groups for the browser: ULIDs and labels, never ids.
 */
final class CriminalGroupPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(CriminalGroup $group): array
    {
        return [
            'ulid' => $group->ulid,
            'name' => $group->name,
            'other_names' => $group->other_names,
            'type' => ['value' => $group->type->value, 'label' => $group->type->label()],
            'status' => ['value' => $group->status->value, 'label' => $group->status->label()],
            'estimated_size' => $group->estimated_size,
            'classification' => ['value' => $group->classificationLevel()->value, 'label' => $group->classificationLevel()->label()],
            'lga' => ['ulid' => $group->lga->ulid, 'name' => $group->lga->name],
            'state' => ['ulid' => $group->state->ulid, 'name' => $group->state->name],
            // Members whose person the viewer can see.
            'member_count' => (int) ($group->getAttribute('visible_members_count') ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(CriminalGroup $group): array
    {
        return [
            ...self::row($group),
            'description' => $group->description,
            'created_at' => $group->created_at?->toIso8601String(),
            'updated_at' => $group->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function area(CriminalGroupArea $area): array
    {
        return [
            'ulid' => $area->ulid,
            'lga' => ['ulid' => $area->lga->ulid, 'name' => $area->lga->name],
            'state' => ['ulid' => $area->state->ulid, 'name' => $area->state->name],
            'notes' => $area->notes,
            'latitude' => $area->getAttribute('latitude') === null ? null : (float) $area->getAttribute('latitude'),
            'longitude' => $area->getAttribute('longitude') === null ? null : (float) $area->getAttribute('longitude'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function member(CriminalGroupMember $member, bool $withGroup = false): array
    {
        return [
            'ulid' => $member->ulid,
            'person' => $member->person === null ? null : PersonPresenter::row($member->person),
            'group' => $withGroup ? self::row($member->group) : null,
            'role_in_group' => $member->role_in_group,
            'membership_status' => ['value' => $member->membership_status->value, 'label' => $member->membership_status->label()],
            'since' => $member->since?->toDateString(),
            'notes' => $member->notes,
            'is_poi' => $member->person?->relationLoaded('personOfInterest') === true && $member->person->personOfInterest !== null,
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        $option = fn (CriminalGroupType|CriminalGroupStatus|MembershipStatus|Classification $c) => ['value' => $c->value, 'label' => $c->label()];

        return [
            'types' => array_map($option, CriminalGroupType::cases()),
            'statuses' => array_map($option, CriminalGroupStatus::cases()),
            'membershipStatuses' => array_map($option, MembershipStatus::cases()),
            'classifications' => array_map($option, Classification::cases()),
        ];
    }
}
