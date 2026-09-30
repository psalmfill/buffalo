<?php

namespace App\Models;

use App\Enums\PatrolFrequency;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One agency's deployment in a coverage report (Phase 4A). Carries its own
 * state_id/lga_id (copied from the report; access rule 9) and is scoped on
 * them, AND is visible only while its report is (which adds the report's
 * classification).
 *
 * @property int $id
 * @property string $ulid
 * @property int $coverage_report_id
 * @property int $state_id
 * @property int $lga_id
 * @property int $security_agency_id
 * @property int|null $agency_formation_id
 * @property int $personnel_count
 * @property int|null $vehicles_count
 * @property PatrolFrequency $patrol_frequency
 * @property string|null $areas_covered
 * @property string|null $remarks
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read CoverageReport $report
 * @property-read SecurityAgency $agency
 * @property-read AgencyFormation|null $formation
 */
class CoverageReportDeployment extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    protected $fillable = ['personnel_count', 'vehicles_count', 'patrol_frequency', 'areas_covered', 'remarks'];

    protected $hidden = ['id', 'coverage_report_id', 'state_id', 'lga_id', 'security_agency_id', 'agency_formation_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'patrol_frequency' => PatrolFrequency::class,
            'personnel_count' => 'integer',
            'vehicles_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Visible only while the report is (jurisdiction AND classification).
        static::addGlobalScope('report', fn (Builder $query) => $query->whereHas('report'));
    }

    /**
     * @return BelongsTo<CoverageReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(CoverageReport::class, 'coverage_report_id');
    }

    /**
     * @return BelongsTo<SecurityAgency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(SecurityAgency::class, 'security_agency_id');
    }

    /**
     * The formation, when the viewer can see it (its own scopes apply).
     *
     * @return BelongsTo<AgencyFormation, $this>
     */
    public function formation(): BelongsTo
    {
        return $this->belongsTo(AgencyFormation::class, 'agency_formation_id');
    }

    /**
     * @return BelongsToMany<Community, $this>
     */
    public function communities(): BelongsToMany
    {
        return $this->belongsToMany(Community::class, 'coverage_deployment_communities', 'coverage_report_deployment_id', 'community_id');
    }
}
