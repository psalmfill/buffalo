<?php

namespace App\Models;

use App\Enums\GoLiveDecision;
use App\Models\Concerns\HasUlidRouteKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded client decision (Phase 9B.6). Append-only: never updated or
 * deleted; a newer record for the same decision supersedes it.
 *
 * @property int $id
 * @property string $ulid
 * @property GoLiveDecision $decision
 * @property string $outcome
 * @property string $details
 * @property string $client_representative
 * @property CarbonImmutable $decided_on
 * @property string|null $reference_version
 * @property int $recorded_by
 * @property CarbonImmutable $created_at
 */
class GoLiveDecisionRecord extends Model
{
    use HasUlidRouteKey;

    protected $table = 'golive_decisions';

    public const UPDATED_AT = null;

    protected $hidden = ['id', 'recorded_by', 'chain_seq', 'prev_hash', 'row_hash'];

    protected $fillable = ['decision', 'outcome', 'details', 'client_representative', 'decided_on', 'reference_version'];

    protected function casts(): array
    {
        return [
            'decision' => GoLiveDecision::class,
            'decided_on' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * The current record for each decision (the newest).
     *
     * @return array<string, self>
     */
    public static function current(): array
    {
        return self::query()->with('recorder')->orderBy('id')->get()
            ->keyBy(fn (self $record): string => $record->decision->value)
            ->all();
    }
}
