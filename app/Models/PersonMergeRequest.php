<?php

namespace App\Models;

use App\Enums\MergeRequestStatus;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to merge two persons (Phase 10A.2), raised by an LGA chief or
 * a Deputy State Director and executed or declined by the State Director
 * (or a national user). Jurisdiction: the requester's state and LGA.
 *
 * @property int $id
 * @property string $ulid
 * @property int $state_id
 * @property int|null $lga_id
 * @property int $person_a_id
 * @property int $person_b_id
 * @property int|null $suggested_survivor_id
 * @property string $reason
 * @property int $requested_by
 * @property MergeRequestStatus $status
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Person|null $personA
 * @property-read Person|null $personB
 * @property-read User $requester
 * @property-read User|null $decider
 * @property-read State $state
 * @property-read Lga|null $lga
 */
class PersonMergeRequest extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    protected $hidden = ['id', 'state_id', 'lga_id', 'person_a_id', 'person_b_id', 'suggested_survivor_id', 'requested_by', 'decided_by'];

    protected $fillable = ['reason'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => MergeRequestStatus::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function involves(int $personId): bool
    {
        return $this->person_a_id === $personId || $this->person_b_id === $personId;
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function personA(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_a_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function personB(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_b_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
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
}
