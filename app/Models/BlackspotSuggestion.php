<?php

namespace App\Models;

use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A suggested blackspot (Phase 6C): a cluster of incidents found by the
 * nightly DBSCAN job (GenerateBlackspotSuggestions), not yet covered by a
 * flashpoint. Scoped by the place of the cluster's centre.
 *
 * The stored `incident_count` is the full cluster size and is never shown:
 * pages count and list only the member incidents the viewer can see
 * (BlackspotSuggestionRows).
 *
 * @property int $id
 * @property string $ulid
 * @property int $state_id
 * @property int $lga_id
 * @property int $radius_meters
 * @property int $incident_count
 * @property int|null $dominant_incident_type_id
 * @property string|null $category
 * @property CarbonImmutable $first_occurred_at
 * @property CarbonImmutable $last_occurred_at
 * @property string $status
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $dismiss_reason
 * @property int|null $flashpoint_id
 * @property CarbonImmutable $generated_at
 * @property float|null $latitude only with withCoordinates()
 * @property float|null $longitude only with withCoordinates()
 * @property-read Lga $lga
 * @property-read State $state
 * @property-read IncidentType|null $dominantType
 */
class BlackspotSuggestion extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'state_id', 'lga_id', 'centroid', 'dominant_incident_type_id', 'decided_by', 'flashpoint_id', 'incident_count'];

    protected function casts(): array
    {
        return [
            'first_occurred_at' => 'immutable_datetime',
            'last_occurred_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @param  Builder<BlackspotSuggestion>  $query
     * @return Builder<BlackspotSuggestion>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('blackspot_suggestions.*');
        }

        return $query->selectRaw('ST_Y(blackspot_suggestions.centroid::geometry) AS latitude, ST_X(blackspot_suggestions.centroid::geometry) AS longitude');
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return BelongsTo<IncidentType, $this>
     */
    public function dominantType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class, 'dominant_incident_type_id');
    }

    /**
     * Member incidents, through the viewer's incident scopes.
     *
     * @return BelongsToMany<Incident, $this>
     */
    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class, 'blackspot_suggestion_incidents');
    }
}
