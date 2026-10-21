<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Models\OrganizationContact;
use App\Models\OrganizationSite;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use Illuminate\Database\Eloquent\Builder;

/**
 * A site's contacts: the organization site page's Contacts list (Phase 4B,
 * 11E export). SENSITIVE. Organization, site, name, position and the
 * management, security and primary flags; never the (encrypted) phone or
 * email, nor the linked person or notes.
 */
final class OrganizationContactsExporter extends ListExporter
{
    public function key(): string
    {
        return 'organization_contacts';
    }

    public function label(): string
    {
        return 'Organization contacts';
    }

    public function page(): string
    {
        return 'organization-sites.show#contacts';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', OrganizationSite::class);
    }

    public function sensitive(): bool
    {
        return true;
    }

    public function rules(User $user): array
    {
        return ['site' => ['required', 'string', 'size:26']];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<OrganizationContact>
     */
    public function query(User $user, array $filters): Builder
    {
        $site = OrganizationSite::query()->where('ulid', (string) ($filters['site'] ?? ''))->first();

        return OrganizationContact::query()
            ->where('organization_site_id', $site->id ?? 0)
            ->orderByDesc('is_primary')->orderByDesc('is_management')->orderByDesc('is_security_contact')->orderBy('name')
            ->orderBy('organization_contacts.id');
    }

    /** Contacts carry their site's classification. */
    public function levels(User $user, array $filters): iterable
    {
        return OrganizationSite::query()->where('ulid', (string) ($filters['site'] ?? ''))->pluck('classification');
    }

    public function with(): array
    {
        return ['site.organization'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('organization', 'Organization', fn (OrganizationContact $c) => $c->site->organization->name),
            ListColumn::make('site', 'Site', fn (OrganizationContact $c) => $c->site->name),
            ListColumn::make('name', 'Name', fn (OrganizationContact $c) => $c->name),
            ListColumn::make('position', 'Position', fn (OrganizationContact $c) => $c->position),
            ListColumn::make('management', 'Management', fn (OrganizationContact $c) => $c->is_management),
            ListColumn::make('security', 'Security contact', fn (OrganizationContact $c) => $c->is_security_contact),
            ListColumn::make('primary', 'Primary contact', fn (OrganizationContact $c) => $c->is_primary),
        ];
    }

    public function defaultColumns(): array
    {
        return ['organization', 'site', 'name', 'position', 'management', 'security', 'primary'];
    }
}
