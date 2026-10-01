<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Models\Concerns\HasJurisdiction;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One status change of an incident (Phase 5C). Append-only (database
 * trigger): only its place may change, following the incident. Scoped on
 * its own place and visible only while the incident is.
 *
 * @property int $id
 * @property int $incident_id
 * @property int $state_id
 * @property int $lga_id
 * @property IncidentStatus|null $from_status
 * @property IncidentStatus $to_status
 * @property string|null $comment
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 */
class IncidentStatusHistory extends Model implements JurisdictionScoped
{
    use HasJurisdiction;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'incident_id', 'state_id', 'lga_id', 'changed_by'];

    protected static function booted(): void
    {
        static::addGlobalScope('incident', fn (Builder $query) => $query->whereHas('incident'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => IncidentStatus::class,
            'to_status' => IncidentStatus::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
