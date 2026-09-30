<?php

namespace App\Models;

use App\Enums\SecurityArrangement;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Government security protecting an organization site (Phase 4B): a link
 * between a site and an agency formation. Visible only when BOTH the site
 * and the formation are visible to the viewer (their own scopes).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_site_id
 * @property int $agency_formation_id
 * @property SecurityArrangement $arrangement
 * @property string|null $notes
 * @property-read OrganizationSite $site
 * @property-read AgencyFormation $formation
 */
class OrganizationSiteFormation extends Model
{
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    protected $fillable = ['arrangement', 'notes'];

    protected $hidden = ['id', 'organization_site_id', 'agency_formation_id', 'created_by'];

    protected function casts(): array
    {
        return ['arrangement' => SecurityArrangement::class];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('visible', fn (Builder $query) => $query->whereHas('site')->whereHas('formation'));
    }

    /**
     * @return BelongsTo<OrganizationSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(OrganizationSite::class, 'organization_site_id');
    }

    /**
     * @return BelongsTo<AgencyFormation, $this>
     */
    public function formation(): BelongsTo
    {
        return $this->belongsTo(AgencyFormation::class, 'agency_formation_id');
    }
}
