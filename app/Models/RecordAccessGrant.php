<?php

namespace App\Models;

use App\Enums\GrantScope;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Temporary read-only access to one record for one user (Phase 3C),
 * created by approving a request. Active: started, not expired, not
 * revoked. Read by the grant resolver (App\Support\Jurisdiction), which
 * lets the record through the jurisdiction scope, never the
 * classification scope.
 *
 * @property int $id
 * @property string $ulid
 * @property int $request_id
 * @property int $user_id
 * @property string $subject_type
 * @property int $subject_id
 * @property GrantScope $scope
 * @property int $granted_by
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by
 * @property string|null $revocation_reason
 * @property CarbonImmutable|null $expiry_reminded_at
 * @property CarbonImmutable|null $expiry_notified_at
 * @property-read RecordAccessRequest $request
 * @property-read User $user
 * @property-read User $granter
 */
class RecordAccessGrant extends Model
{
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'request_id', 'user_id', 'subject_id', 'granted_by', 'revoked_by'];

    protected function casts(): array
    {
        return [
            'scope' => GrantScope::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expiry_reminded_at' => 'datetime',
            'expiry_notified_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<RecordAccessGrant>  $query
     * @return Builder<RecordAccessGrant>
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = CarbonImmutable::now();

        return $query
            ->whereNull('record_access_grants.revoked_at')
            ->where('record_access_grants.starts_at', '<=', $now)
            ->where('record_access_grants.expires_at', '>', $now);
    }

    public function isActive(): bool
    {
        $now = CarbonImmutable::now();

        return $this->revoked_at === null && $this->starts_at->lessThanOrEqualTo($now) && $this->expires_at->greaterThan($now);
    }

    /**
     * @return BelongsTo<RecordAccessRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(RecordAccessRequest::class, 'request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
