<?php

namespace App\Support\Users;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Account statuses as query filters. One definition for the user list
 * filter and the system dashboard counts, so a count links to the same rows.
 */
final class UserStatusFilter
{
    public const STATUSES = ['active', 'inactive', 'locked', 'setup_pending', 'no_2fa'];

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function apply(Builder $query, string $status): Builder
    {
        return match ($status) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'locked' => $query->where('locked_until', '>', now()),
            'setup_pending' => $query->whereNull('password_changed_at'),
            'no_2fa' => $query->whereNull('two_factor_confirmed_at'),
            default => $query,
        };
    }
}
