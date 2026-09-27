<?php

namespace App\Models;

use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A State Director's delegation of access-request approval to a Deputy
 * State Director of the same state (Phase 3C). State-level record: scoped
 * to the state (LGA chiefs see none).
 *
 * Active: started, not revoked, and not ended (ends_at null or future).
 * Ends automatically when either party's officer posting closes.
 *
 * @property int $id
 * @property string $ulid
 * @property int $state_id
 * @property int $delegator_id
 * @property int $delegate_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property string|null $reason
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by
 * @property string|null $end_reason
 * @property int|null $created_by
 * @property-read State $state
 * @property-read User $delegator
 * @property-read User $delegate
 */
class ApprovalDelegation extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    /** State-level records. */
    protected ?string $jurisdictionLgaColumn = null;

    protected $fillable = ['starts_at', 'ends_at', 'reason'];

    protected $hidden = ['id', 'state_id', 'delegator_id', 'delegate_id', 'revoked_by', 'created_by'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<ApprovalDelegation>  $query
     * @return Builder<ApprovalDelegation>
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = CarbonImmutable::now();

        return $query
            ->whereNull('approval_delegations.revoked_at')
            ->where('approval_delegations.starts_at', '<=', $now)
            ->where(fn (Builder $q) => $q->whereNull('approval_delegations.ends_at')->orWhere('approval_delegations.ends_at', '>', $now));
    }

    public function isActive(): bool
    {
        $now = CarbonImmutable::now();

        return $this->revoked_at === null
            && $this->starts_at->lessThanOrEqualTo($now)
            && ($this->ends_at === null || $this->ends_at->greaterThan($now));
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }
}
