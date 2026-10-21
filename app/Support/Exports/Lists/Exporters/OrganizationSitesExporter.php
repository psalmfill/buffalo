<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\SecurityRelevance;
use App\Models\Organization;
use App\Models\OrganizationSite;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Organization sites (Phase 4B lists, 11E export): the LGA page's
 * Organizations tab (by relevance) and an organization's sites (by name),
 * through the site scopes (jurisdiction and classification). Contacts are
 * the separate, sensitive organization_contacts list.
 */
final class OrganizationSitesExporter extends ListExporter
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'organization_sites';
    }

    public function label(): string
    {
        return 'Organization sites';
    }

    public function page(): string
    {
        return 'lgas.show#organizations';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', OrganizationSite::class);
    }

    public function rules(User $user): array
    {
        return [
            'lga' => ['nullable', 'string', 'size:26'],
            'organization' => ['nullable', 'string', 'size:26'],
            'relevance' => ['nullable', Rule::enum(SecurityRelevance::class)],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<OrganizationSite>
     */
    public function query(User $user, array $filters): Builder
    {
        $lga = isset($filters['lga']) ? $this->options->lgaQuery($user)->where('ulid', (string) $filters['lga'])->first() : null;
        $organization = isset($filters['organization']) ? Organization::query()->where('ulid', (string) $filters['organization'])->first() : null;

        return OrganizationSite::query()->withCoordinates()
            ->when(isset($filters['lga']), fn (Builder $q) => $q->where('organization_sites.lga_id', $lga->id ?? 0))
            ->when(isset($filters['organization']), fn (Builder $q) => $q->where('organization_sites.organization_id', $organization->id ?? 0))
            ->when($filters['relevance'] ?? null, fn (Builder $q, string $relevance) => $q->where('organization_sites.security_relevance', $relevance))
            ->when(! isset($filters['organization']), fn (Builder $q) => $q
                ->orderByRaw("CASE organization_sites.security_relevance WHEN 'critical_infrastructure' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END"))
            ->orderBy('organization_sites.name')
            ->orderBy('organization_sites.id');
    }

    public function classificationColumn(): string
    {
        return 'organization_sites.classification';
    }

    public function with(): array
    {
        return ['organization.sector', 'state', 'lga', 'community', 'securityProvider'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('name', 'Site', fn (OrganizationSite $s) => $s->name),
            ListColumn::make('organization', 'Organization', fn (OrganizationSite $s) => $s->organization->name),
            ListColumn::make('sector', 'Sector', fn (OrganizationSite $s) => $s->organization->sector->name),
            ListColumn::make('type', 'Site type', fn (OrganizationSite $s) => $s->site_type->label()),
            ListColumn::make('relevance', 'Security relevance', fn (OrganizationSite $s) => $s->security_relevance->label()),
            ListColumn::make('status', 'Status', fn (OrganizationSite $s) => $s->status->label()),
            ListColumn::make('state', 'State', fn (OrganizationSite $s) => $s->state->name),
            ListColumn::make('lga', 'LGA', fn (OrganizationSite $s) => $s->lga->name),
            ListColumn::make('community', 'Community', fn (OrganizationSite $s) => $s->community?->name),
            ListColumn::make('address', 'Address', fn (OrganizationSite $s) => $s->address),
            ListColumn::make('latitude', 'Latitude', fn (OrganizationSite $s) => $s->latitude === null ? null : round((float) $s->latitude, 6)),
            ListColumn::make('longitude', 'Longitude', fn (OrganizationSite $s) => $s->longitude === null ? null : round((float) $s->longitude, 6)),
            ListColumn::make('employees', 'Employees (estimate)', fn (OrganizationSite $s) => $s->employees_estimate),
            ListColumn::make('private_security', 'Private security', fn (OrganizationSite $s) => $s->has_private_security),
            ListColumn::make('provider', 'Security provider', fn (OrganizationSite $s) => $s->securityProvider?->name),
            ListColumn::make('security_notes', 'Security notes', fn (OrganizationSite $s) => self::text($s, 'security_notes')),
            ListColumn::make('classification', 'Classification', fn (OrganizationSite $s) => $s->classificationLevel()->label()),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'organization', 'type', 'relevance', 'lga', 'state', 'classification'];
    }
}
