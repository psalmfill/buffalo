<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\IncidentUpdateType;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A follow-up on an incident (Phase 5C): further facts, action taken,
 * intelligence or a status note. Append-only (database trigger):
 * corrections are new updates. Classified (never below the incident's when
 * written), scoped on its own place, and visible only while its incident
 * is; if the incident is later raised, the update is seen only by those who
 * can see the incident. Views above `restricted` are access-logged.
 *
 * @property int $id
 * @property string $ulid
 * @property int $incident_id
 * @property int $state_id
 * @property int $lga_id
 * @property IncidentUpdateType $type
 * @property string $body
 * @property Classification $classification
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property-read Incident $incident
 * @property-read User $author
 */
class IncidentUpdate extends Model implements Classified, JurisdictionScoped
{
    use HasClassification;
    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use LogsReads;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'incident_id', 'state_id', 'lga_id', 'created_by'];

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
            'type' => IncidentUpdateType::class,
            'classification' => Classification::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function logsViews(): bool
    {
        return $this->classificationLevel() !== Classification::Restricted;
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
