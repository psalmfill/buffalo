<?php

namespace App\Models;

use App\Enums\JurisdictionLevel;
use App\Enums\Role;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * A BUFFALO user. Each user has exactly one role (App\Enums\Role) and one
 * jurisdiction (level + state/LGA, or zones for a DDG).
 *
 * Jurisdiction, role and account-state columns are not mass assignable:
 * they are only changed through App\Actions\Users.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $service_number
 * @property string|null $rank
 * @property JurisdictionLevel $jurisdiction_level
 * @property int|null $state_id
 * @property int|null $lga_id
 * @property bool $is_active
 * @property Carbon|null $deactivated_at
 * @property string|null $deactivation_reason
 * @property int|null $deactivated_by
 * @property int $failed_login_count
 * @property Carbon|null $locked_until
 * @property Carbon|null $password_changed_at
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'email', 'password', 'phone', 'service_number', 'rank'])]
#[Hidden([
    'id',
    'password',
    'remember_token',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'two_factor_confirmed_at',
    'failed_login_count',
    'state_id',
    'lga_id',
    'created_by',
    'updated_by',
    'deactivated_by',
    'roles',
    'permissions',
    'pivot',
])]
class User extends Authenticatable implements JurisdictionScoped
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasJurisdiction, HasRoles, HasUlidRouteKey, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Super admins manage users, so they may see all users. Everyone else
     * sees users in their own jurisdiction (by the user's state/LGA).
     */
    protected bool $visibleToSuperAdmin = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'jurisdiction_level' => JurisdictionLevel::class,
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'failed_login_count' => 'integer',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The user's single role, or null if none is assigned (a user without a
     * role is denied everything).
     */
    public function getRole(): ?Role
    {
        $name = $this->roles->first()?->getAttribute('name');

        return is_string($name) ? Role::tryFrom($name) : null;
    }

    public function hasBuffaloRole(Role $role): bool
    {
        return $this->getRole() === $role;
    }

    /**
     * True when the account must not be able to see or do anything:
     * deactivated, soft-deleted or temporarily locked.
     */
    public function isAccessBlocked(): bool
    {
        return ! $this->is_active
            || $this->trashed()
            || ($this->locked_until !== null && $this->locked_until->isFuture());
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /**
     * Zones a DDG is restricted to; empty means national.
     *
     * @return BelongsToMany<GeopoliticalZone, $this>
     */
    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(GeopoliticalZone::class, 'user_zones')->withTimestamps();
    }

    /**
     * @return HasMany<PasswordHistory, $this>
     */
    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deactivator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }
}
