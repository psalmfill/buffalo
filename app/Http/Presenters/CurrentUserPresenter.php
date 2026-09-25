<?php

namespace App\Http\Presenters;

use App\Enums\JurisdictionLevel;
use App\Models\GeopoliticalZone;
use App\Models\User;

/**
 * The signed-in user as shared with every page (auth.user). ULIDs and names
 * only: no numeric ids, secrets or account-security fields.
 */
final class CurrentUserPresenter
{
    /**
     * @return array{
     *     ulid: string,
     *     name: string,
     *     email: string,
     *     role: array{value: string, label: string}|null,
     *     jurisdiction: array{
     *         level: string,
     *         state: array{ulid: string, name: string}|null,
     *         lga: array{ulid: string, name: string}|null,
     *         zones: list<string>,
     *     },
     *     jurisdictionLabel: string,
     * }
     */
    public static function present(User $user): array
    {
        $role = $user->getRole();
        /** @var list<string> $zones */
        $zones = $user->jurisdiction_level === JurisdictionLevel::National
            ? $user->zones->sortBy('name')->map(fn (GeopoliticalZone $zone): string => $zone->name)->values()->all()
            : [];

        return [
            'ulid' => $user->ulid,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role === null ? null : ['value' => $role->value, 'label' => $role->label()],
            'jurisdiction' => [
                'level' => $user->jurisdiction_level->value,
                'state' => $user->state === null ? null : ['ulid' => $user->state->ulid, 'name' => $user->state->name],
                'lga' => $user->lga === null ? null : ['ulid' => $user->lga->ulid, 'name' => $user->lga->name],
                'zones' => $zones,
            ],
            'jurisdictionLabel' => $user->jurisdictionLabel(),
        ];
    }
}
