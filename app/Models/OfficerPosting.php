<?php

namespace App\Models;

use App\Enums\JurisdictionLevel;
use App\Enums\Role;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One period in which a user held a role in a jurisdiction. Written only by
 * App\Actions\Users\OfficerPostings (through the user actions). At most one
 * open posting (ended_at null) per user, enforced by a partial unique index.
 *
 * Jurisdiction-scoped like users: super admins see all postings, others the
 * postings in their jurisdiction.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property Role $role
 * @property JurisdictionLevel $jurisdiction_level
 * @property int|null $state_id
 * @property int|null $lga_id
 * @property string|null $position_title
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int|null $created_by
 */
class OfficerPosting extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    protected bool $visibleToSuperAdmin = true;

    protected $fillable = [
        'user_id',
        'role',
        'jurisdiction_level',
        'state_id',
        'lga_id',
        'position_title',
        'started_at',
        'ended_at',
        'created_by',
    ];

    protected $hidden = ['id', 'user_id', 'state_id', 'lga_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'jurisdiction_level' => JurisdictionLevel::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * The user who held $role for $place on $date (at that moment), if any.
     * Results follow the caller's jurisdiction scope.
     */
    public static function holderOn(Lga|State $place, Role $role, CarbonInterface $date): ?User
    {
        $posting = static::query()
            ->where('role', $role->value)
            ->where($place instanceof Lga ? 'lga_id' : 'state_id', $place->id)
            ->when($place instanceof State, fn (Builder $query) => $query->whereNull('lga_id'))
            ->where('started_at', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $date))
            ->latest('started_at')
            ->first();

        return $posting?->user;
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
