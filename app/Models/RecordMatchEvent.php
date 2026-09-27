<?php

namespace App\Models;

use App\Enums\MatchContext;
use App\Enums\MatchType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cross-jurisdiction match shown to a user as a hit, or suppressed
 * because the record is above their clearance (Phase 3C). Append-only
 * (database trigger): an audit record, readable only by national oversight
 * and, for their own state's records, State Directors.
 *
 * Not jurisdiction-scoped: it records WHO looked, across jurisdictions;
 * access to it is decided by the pages that list it (AccessRequestController).
 *
 * @property int $id
 * @property int $user_id
 * @property string $subject_type
 * @property int $subject_id
 * @property int $owning_state_id
 * @property int|null $owning_lga_id
 * @property MatchType $match_type
 * @property MatchContext $context
 * @property bool $suppressed
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read State $owningState
 */
class RecordMatchEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'user_id', 'subject_id', 'owning_state_id', 'owning_lga_id'];

    protected function casts(): array
    {
        return [
            'match_type' => MatchType::class,
            'context' => MatchContext::class,
            'suppressed' => 'boolean',
            'created_at' => 'datetime',
        ];
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
    public function owningState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'owning_state_id');
    }
}
