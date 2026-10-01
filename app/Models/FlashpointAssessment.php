<?php

namespace App\Models;

use App\Enums\ConcernLevel;
use App\Enums\FlashpointStatus;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An assessment of a flashpoint (Phase 6A). Append-only (database
 * trigger): a new assessment supersedes the last one. Scoped on its own
 * place (that of the flashpoint, which it follows) and visible only while
 * the flashpoint is. Audited through the flashpoint's named "assessed"
 * event (AssessFlashpoint).
 *
 * @property int $id
 * @property string $ulid
 * @property int $flashpoint_id
 * @property int $state_id
 * @property int $lga_id
 * @property ConcernLevel $concern_level
 * @property FlashpointStatus $status
 * @property string $assessment
 * @property string|null $recommended_action
 * @property int $assessed_by
 * @property CarbonImmutable $assessed_at
 * @property-read Flashpoint $flashpoint
 * @property-read User $assessor
 */
class FlashpointAssessment extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'flashpoint_id', 'state_id', 'lga_id', 'assessed_by'];

    protected static function booted(): void
    {
        static::addGlobalScope('flashpoint', fn (Builder $query) => $query->whereHas('flashpoint'));
    }

    protected function casts(): array
    {
        return [
            'concern_level' => ConcernLevel::class,
            'status' => FlashpointStatus::class,
            'assessed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Flashpoint, $this>
     */
    public function flashpoint(): BelongsTo
    {
        return $this->belongsTo(Flashpoint::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
