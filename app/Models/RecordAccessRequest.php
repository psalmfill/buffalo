<?php

namespace App\Models;

use App\Enums\AccessRequestStatus;
use App\Enums\GrantScope;
use App\Enums\RequestUrgency;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A request for temporary read-only access to a record in another
 * jurisdiction (Phase 3C). Created only from a valid hit token; never
 * deleted.
 *
 * Not jurisdiction-scoped by HasJurisdiction: it belongs to two places
 * (the requester's and the owner's). Who may see a request is decided by
 * RecordAccessRequestPolicy (requester, approvers, oversight); list
 * queries are built for each role by AccessRequestQueries.
 *
 * @property int $id
 * @property string $ulid
 * @property int $requester_id
 * @property int|null $requester_state_id
 * @property int|null $requester_lga_id
 * @property string $subject_type
 * @property int $subject_id
 * @property int $owning_state_id
 * @property int|null $owning_lga_id
 * @property int $match_event_id
 * @property int|null $incident_id
 * @property string $reason
 * @property string|null $reference
 * @property GrantScope $scope
 * @property RequestUrgency $urgency
 * @property int $requested_days
 * @property AccessRequestStatus $status
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property int|null $granted_days
 * @property GrantScope|null $granted_scope
 * @property CarbonImmutable|null $escalated_at
 * @property CarbonImmutable $created_at
 * @property-read User $requester
 * @property-read State|null $requesterState
 * @property-read Lga|null $requesterLga
 * @property-read State $owningState
 * @property-read Lga|null $owningLga
 * @property-read RecordMatchEvent $matchEvent
 * @property-read User|null $decider
 * @property-read RecordAccessGrant|null $grant
 */
class RecordAccessRequest extends Model
{
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'requester_id', 'requester_state_id', 'requester_lga_id', 'subject_id', 'owning_state_id', 'owning_lga_id', 'match_event_id', 'decided_by', 'incident_id'];

    protected function casts(): array
    {
        return [
            'scope' => GrantScope::class,
            'urgency' => RequestUrgency::class,
            'status' => AccessRequestStatus::class,
            'granted_scope' => GrantScope::class,
            'requested_days' => 'integer',
            'granted_days' => 'integer',
            'decided_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function requesterState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'requester_state_id');
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function requesterLga(): BelongsTo
    {
        return $this->belongsTo(Lga::class, 'requester_lga_id');
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function owningState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'owning_state_id');
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function owningLga(): BelongsTo
    {
        return $this->belongsTo(Lga::class, 'owning_lga_id');
    }

    /**
     * @return BelongsTo<RecordMatchEvent, $this>
     */
    public function matchEvent(): BelongsTo
    {
        return $this->belongsTo(RecordMatchEvent::class, 'match_event_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return HasOne<RecordAccessGrant, $this>
     */
    public function grant(): HasOne
    {
        return $this->hasOne(RecordAccessGrant::class, 'request_id');
    }
}
