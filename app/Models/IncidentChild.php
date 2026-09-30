<?php

namespace App\Models;

use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Base for an incident's related rows (Phase 5A): casualties, persons,
 * criminal groups, agencies, property. Each row carries its own state_id
 * and lga_id (kept equal to the incident's by a cascading composite FK) and
 * is scoped on them (access rule 9), AND is visible only while its
 * incident is (which adds the incident's classification). Changes are in
 * the activity log and appear in the incident's history.
 *
 * @property int $id
 * @property string $ulid
 * @property int $incident_id
 * @property int $state_id
 * @property int $lga_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Incident $incident
 */
abstract class IncidentChild extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope('incident', fn (Builder $query) => $query->whereHas('incident'));
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
