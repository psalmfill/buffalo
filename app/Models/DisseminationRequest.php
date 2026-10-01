<?php

namespace App\Models;

use App\Enums\DisseminationRequestStatus;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An LGA chief's request (or the creator's) that the State Director or
 * Deputy disseminate a threat report or flashpoint (Phase 7A). Scoped on
 * the subject's place; the subject itself is read through the viewer's
 * scopes.
 *
 * @property int $id
 * @property string $ulid
 * @property string $subject_type
 * @property int $subject_id
 * @property int $state_id
 * @property int $lga_id
 * @property int $requested_by
 * @property list<array{level: string, ulid: string}> $suggested_targets
 * @property string $reason
 * @property DisseminationRequestStatus $status
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable $created_at
 * @property-read State $state
 * @property-read Lga $lga
 */
class DisseminationRequest extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'subject_id', 'state_id', 'lga_id', 'requested_by', 'decided_by'];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'suggested_targets' => 'array',
            'status' => DisseminationRequestStatus::class,
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
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
