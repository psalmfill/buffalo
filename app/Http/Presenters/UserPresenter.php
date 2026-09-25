<?php

namespace App\Http\Presenters;

use App\Models\OfficerPosting;
use App\Models\User;

/**
 * User data sent to the browser: ULIDs and labels only, never numeric ids
 * or secrets.
 */
final class UserPresenter
{
    /**
     * Status badges, in order of importance.
     *
     * @return list<'inactive'|'locked'|'setup_pending'|'no_2fa'|'active'>
     */
    public static function statuses(User $user): array
    {
        $statuses = [];

        if (! $user->is_active) {
            $statuses[] = 'inactive';
        }

        if ($user->locked_until !== null && $user->locked_until->isFuture()) {
            $statuses[] = 'locked';
        }

        if ($user->password_changed_at === null) {
            $statuses[] = 'setup_pending';
        }

        if ($user->two_factor_confirmed_at === null) {
            $statuses[] = 'no_2fa';
        }

        return $statuses === [] ? ['active'] : $statuses;
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(User $user): array
    {
        $role = $user->getRole();

        return [
            'ulid' => $user->ulid,
            'name' => $user->name,
            'email' => $user->email,
            'service_number' => $user->service_number,
            'rank' => $user->rank,
            'role' => $role === null ? null : ['value' => $role->value, 'label' => $role->label()],
            'jurisdiction' => $user->jurisdictionLabel(),
            'statuses' => self::statuses($user),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(User $user): array
    {
        return [
            ...self::row($user),
            'phone' => $user->phone,
            'jurisdiction_level' => $user->jurisdiction_level->value,
            'state' => $user->state === null ? null : ['ulid' => $user->state->ulid, 'name' => $user->state->name],
            'lga' => $user->lga === null ? null : ['ulid' => $user->lga->ulid, 'name' => $user->lga->name],
            'zones' => $user->zones->map(fn ($zone) => ['ulid' => $zone->ulid, 'name' => $zone->name])->values()->all(),
            'is_active' => $user->is_active,
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'deactivation_reason' => $user->deactivation_reason,
            'locked_until' => $user->locked_until?->toIso8601String(),
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            'two_factor_confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'last_login_ip' => $user->last_login_ip,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function posting(OfficerPosting $posting): array
    {
        return [
            'ulid' => $posting->ulid,
            'role' => $posting->role->label(),
            'jurisdiction' => match (true) {
                $posting->lga !== null => $posting->lga->name.' LGA, '.($posting->state->name ?? '?'),
                $posting->state !== null => $posting->state->name.' State',
                default => $posting->jurisdiction_level->label(),
            },
            'position_title' => $posting->position_title,
            'started_at' => $posting->started_at->toIso8601String(),
            'ended_at' => $posting->ended_at?->toIso8601String(),
        ];
    }
}
