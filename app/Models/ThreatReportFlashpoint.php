<?php

namespace App\Models;

use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A link between a threat report and a flashpoint (Phase 6B). The row sits
 * on the threat report's place (cascading composite FK) and is visible
 * only when BOTH the threat report and the linked record are visible to
 * the viewer: counts through this model never include hidden links.
 * Unlinking soft-deletes the row; both are named events on the report.
 *
 * @property int $id
 * @property string $ulid
 * @property int $threat_report_id
 * @property int $state_id
 * @property int $lga_id
 * @property int $flashpoint_id
 * @property int|null $linked_by
 * @property CarbonImmutable $linked_at
 * @property int|null $unlinked_by
 * @property-read ThreatReport $threatReport
 * @property-read Flashpoint $flashpoint
 */
class ThreatReportFlashpoint extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'threat_report_flashpoints';

    protected $guarded = ['*'];

    protected $hidden = ['id', 'threat_report_id', 'state_id', 'lga_id', 'flashpoint_id', 'linked_by', 'unlinked_by'];

    protected static function booted(): void
    {
        static::addGlobalScope('both_visible', fn (Builder $query) => $query->whereHas('threatReport')->whereHas('flashpoint'));
    }

    protected function casts(): array
    {
        return [
            'linked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ThreatReport, $this>
     */
    public function threatReport(): BelongsTo
    {
        return $this->belongsTo(ThreatReport::class);
    }

    /**
     * @return BelongsTo<Flashpoint, $this>
     */
    public function flashpoint(): BelongsTo
    {
        return $this->belongsTo(Flashpoint::class, 'flashpoint_id');
    }
}
