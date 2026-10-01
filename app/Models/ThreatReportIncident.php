<?php

namespace App\Models;

use App\Enums\ThreatIncidentRelation;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A link between a threat report and an incident (with its relation) (Phase 6B). The row sits
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
 * @property int $incident_id
 * @property ThreatIncidentRelation $relation
 * @property int|null $linked_by
 * @property CarbonImmutable $linked_at
 * @property int|null $unlinked_by
 * @property-read ThreatReport $threatReport
 * @property-read Incident $incident
 */
class ThreatReportIncident extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'threat_report_incidents';

    protected $guarded = ['*'];

    protected $hidden = ['id', 'threat_report_id', 'state_id', 'lga_id', 'incident_id', 'linked_by', 'unlinked_by'];

    protected static function booted(): void
    {
        static::addGlobalScope('both_visible', fn (Builder $query) => $query->whereHas('threatReport')->whereHas('incident'));
    }

    protected function casts(): array
    {
        return [
            'relation' => ThreatIncidentRelation::class,
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
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }
}
