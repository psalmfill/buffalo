<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationSector;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use App\Support\Persons\PersonSearch;
use App\Support\Persons\PhoneNumber;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The organization directory (Phase 4B list, 11E export): national entries
 * with the sites the requester can see counted. A search that looks up a
 * contact by phone or email is never exported (contacts are the
 * sensitive organization_contacts list); such a term exports nothing.
 */
final class OrganizationsExporter extends ListExporter
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'organizations';
    }

    public function label(): string
    {
        return 'Organizations';
    }

    public function page(): string
    {
        return 'organizations.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', Organization::class);
    }

    public function rules(User $user): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(OrganizationType::class)],
            'sector' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::enum(OrganizationStatus::class)],
            'in_my_area' => ['nullable', Rule::in(['1'])],
            // Organizations with a site (visible to the user) in a state or
            // zone (Phase 11A.2, the state lens).
            'state' => ['nullable', 'string', Rule::in($this->options->states($user)->pluck('ulid'))],
            'zone' => PlaceFilter::zoneRule($user),
        ];
    }

    /**
     * The directory query with the page's filters; a name search orders by
     * relevance (run it inside around()). A contact lookup term is not a
     * name search: the page lists the directory by name beside the contacts.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Organization>
     */
    public function filtered(User $user, array $filters): Builder
    {
        $state = isset($filters['state']) ? $this->options->states($user)->firstWhere('ulid', $filters['state']) : null;
        $zone = PlaceFilter::zone($filters['zone'] ?? null);
        $term = trim((string) ($filters['q'] ?? ''));
        $sector = isset($filters['sector']) ? OrganizationSector::query()->where('ulid', $filters['sector'])->first() : null;

        $query = Organization::query()
            ->withCount('sites')
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('organizations.type', $type))
            ->when($sector, fn (Builder $q) => $q->where('organizations.organization_sector_id', $sector?->id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('organizations.status', $status))
            ->when(isset($filters['in_my_area']), fn (Builder $q) => $q->whereHas('sites'))
            ->when($state, fn (Builder $q) => $q->whereHas('sites', fn (Builder $s) => $s->where('organization_sites.state_id', $state?->id)))
            ->when($zone, fn (Builder $q) => $q->whereHas('sites', fn (Builder $s) => PlaceFilter::apply($s, 'organization_sites.state_id', $zone)));

        return $term !== '' && ! self::isLookup($term) ? $query->search($term) : $query->orderBy('organizations.name');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Organization>
     */
    public function query(User $user, array $filters): Builder
    {
        return $this->filtered($user, $filters)
            ->when(self::isLookup((string) ($filters['q'] ?? '')), fn (Builder $q) => $q->whereRaw('1 = 0'));
    }

    /** Whether a search term looks up a contact (an email or a Nigerian phone number). */
    public static function isLookup(string $term): bool
    {
        $term = trim($term);

        return $term !== '' && (str_contains($term, '@')
            || (preg_match('/^[\d\s+()\-.\/]{7,}$/', $term) === 1 && PhoneNumber::normalise($term) !== null));
    }

    public function around(Closure $callback): mixed
    {
        return PersonSearch::withThresholds(PersonSearch::SIMILARITY, PersonSearch::WORD_SIMILARITY, $callback);
    }

    public function with(): array
    {
        return ['sector'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('name', 'Name', fn (Organization $o) => $o->name),
            ListColumn::make('other_names', 'Other names', fn (Organization $o) => implode(', ', $o->other_names)),
            ListColumn::make('type', 'Type', fn (Organization $o) => $o->type->label()),
            ListColumn::make('sector', 'Sector', fn (Organization $o) => $o->sector->name),
            ListColumn::make('status', 'Status', fn (Organization $o) => $o->status->label()),
            ListColumn::make('rc_number', 'RC number', fn (Organization $o) => $o->rc_number),
            ListColumn::make('website', 'Website', fn (Organization $o) => $o->website),
            ListColumn::make('sites', 'Sites visible to you', fn (Organization $o) => (int) $o->getAttribute('sites_count')),
            ListColumn::make('description', 'Description', fn (Organization $o) => self::text($o, 'description')),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'other_names', 'type', 'sector', 'status', 'rc_number', 'sites'];
    }
}
