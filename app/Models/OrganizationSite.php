<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\SecurityRelevance;
use App\Enums\SiteStatus;
use App\Enums\SiteType;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationSiteFactory;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A physical site of an organization (Phase 4B): head office, facility,
 * terminal, branch… Scoped by its own jurisdiction and classified; it holds
 * the security-relevant information. Views of confidential and secret sites
 * are access-logged.
 *
 * `location` is a geography(Point, 4326): write it with pointFrom(), read
 * it with withCoordinates().
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property string $name
 * @property SiteType $site_type
 * @property int $state_id
 * @property int $lga_id
 * @property int|null $community_id
 * @property string|null $address
 * @property int|null $employees_estimate
 * @property SecurityRelevance $security_relevance
 * @property bool $has_private_security
 * @property int|null $security_provider_organization_id
 * @property string|null $security_notes
 * @property SiteStatus $status
 * @property Classification $classification
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property float|null $latitude only with withCoordinates()
 * @property float|null $longitude only with withCoordinates()
 * @property-read Organization $organization
 * @property-read Organization|null $securityProvider
 * @property-read State $state
 * @property-read Lga $lga
 * @property-read Community|null $community
 */
class OrganizationSite extends Model implements Classified, JurisdictionScoped
{
    use HasClassification;

    /** @use HasFactory<OrganizationSiteFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    protected $fillable = ['name', 'site_type', 'address', 'employees_estimate', 'security_relevance', 'has_private_security', 'security_notes', 'status'];

    protected $hidden = [
        'id', 'organization_id', 'state_id', 'lga_id', 'community_id', 'security_provider_organization_id',
        'location', 'created_by', 'updated_by',
    ];

    protected $attributes = [
        'classification' => 'restricted',
        'status' => 'active',
        'has_private_security' => false,
    ];

    protected function casts(): array
    {
        return [
            'site_type' => SiteType::class,
            'security_relevance' => SecurityRelevance::class,
            'status' => SiteStatus::class,
            'classification' => Classification::class,
            'has_private_security' => 'boolean',
            'employees_estimate' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Location changes are a named event with coordinates (SaveOrganizationSite).
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['location'];
    }

    public static function pointFrom(?float $latitude, ?float $longitude): ?Expression
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        // Only floats, formatted with %F (locale-independent): nothing to inject.
        // @phpstan-ignore argument.type
        return DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', $longitude, $latitude));
    }

    /**
     * @param  Builder<OrganizationSite>  $query
     * @return Builder<OrganizationSite>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('organization_sites.*');
        }

        return $query->selectRaw('ST_Y(organization_sites.location::geometry) AS latitude, ST_X(organization_sites.location::geometry) AS longitude');
    }

    /** Views of confidential and secret sites are access-logged. */
    public function logsViews(): bool
    {
        return $this->classificationLevel() !== Classification::Restricted;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function securityProvider(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'security_provider_organization_id');
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /**
     * @return BelongsTo<Community, $this>
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * Contacts, through their own scopes.
     *
     * @return HasMany<OrganizationContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(OrganizationContact::class);
    }

    /**
     * @return HasMany<OrganizationSiteFormation, $this>
     */
    public function formationLinks(): HasMany
    {
        return $this->hasMany(OrganizationSiteFormation::class);
    }
}
