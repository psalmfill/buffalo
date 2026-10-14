<?php

namespace App\Services\Search\Providers;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\OrganizationSite;
use App\Models\User;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Organizations:
 * - text: directory names (national, `<%` on organizations.search_name)
 *   and site names (jurisdiction-scoped and classified, `<%` on
 *   organization_sites.search_name);
 * - phone: organization contacts whose phone matches exactly (blind index,
 *   scoped, and each checked with `view`). The hit names the organization
 *   and site only, never the contact; opening the organization's lookup
 *   logs the contact view as today.
 */
final class OrganizationProvider extends BaseProvider
{
    private const CONTACT_CAP = 50;

    public function key(): string
    {
        return 'organizations';
    }

    public function label(): string
    {
        return 'Organizations';
    }

    protected function permission(): string
    {
        return Permission::OrganizationsView->value;
    }

    public function supports(SearchQuery $query): bool
    {
        return $query->isText() || $query->phone !== null;
    }

    public function hits(SearchQuery $query, User $user, int $limit, int $offset = 0): array
    {
        if (! $query->isText()) {
            return array_slice($this->contactHits($query, $user), $offset, $limit);
        }

        $take = $offset + $limit;
        $organizations = $this->organizations($query)
            ->select('organizations.*')
            ->selectRaw('word_similarity(buffalo_search_text(?), organizations.search_name) AS search_score', [$query->raw])
            ->with('sector')
            ->orderByDesc('search_score')->orderBy('organizations.name')
            ->limit($take)->get()
            ->map(fn (Organization $o) => new SearchHit(
                'organizations',
                $o->ulid,
                $o->name,
                self::subtitle($o->type->label(), $o->sector->name, 'Directory'),
                '/organizations/'.$o->ulid,
                null,
                self::score($o->getAttribute('search_score')),
            ));
        $sites = $this->sites($query)
            ->select('organization_sites.*')
            ->selectRaw('word_similarity(buffalo_search_text(?), organization_sites.search_name) AS search_score', [$query->raw])
            ->with(['organization', 'lga'])
            ->tap(fn (Builder $q) => self::preferStates($q, $query, 'organization_sites.state_id'))
            ->orderByDesc('search_score')->orderBy('organization_sites.name')
            ->limit($take)->get()
            ->map(fn (OrganizationSite $s) => new SearchHit(
                'organizations',
                $s->ulid,
                $s->name,
                self::subtitle('Site of '.$s->organization->name, $s->site_type->label(), $s->lga->name.' LGA'),
                '/organizations/'.$s->organization->ulid,
                $s->classificationLevel(),
                self::score($s->getAttribute('search_score')),
            ));

        return array_slice(SearchHit::rank([...$organizations->all(), ...$sites->all()]), $offset, $limit);
    }

    public function count(SearchQuery $query, User $user): int
    {
        return $query->isText()
            ? $this->organizations($query)->count() + $this->sites($query)->count()
            : count($this->contactHits($query, $user));
    }

    /**
     * @return Builder<Organization>
     */
    private function organizations(SearchQuery $query): Builder
    {
        return Organization::query()->whereRaw('buffalo_search_text(?) <% organizations.search_name', [$query->raw]);
    }

    /**
     * @return Builder<OrganizationSite>
     */
    private function sites(SearchQuery $query): Builder
    {
        return OrganizationSite::query()->whereRaw('buffalo_search_text(?) <% organization_sites.search_name', [$query->raw]);
    }

    /**
     * @return list<SearchHit>
     */
    private function contactHits(SearchQuery $query, User $user): array
    {
        if ($query->phone === null) {
            return [];
        }

        return array_values(OrganizationContact::query()
            ->with(['site.organization', 'site.lga'])
            ->whereBlind('phone', 'phone_bidx', $query->phone)
            ->limit(self::CONTACT_CAP)
            ->get()
            ->filter(fn (OrganizationContact $c) => $user->can('view', $c))
            ->map(fn (OrganizationContact $c) => new SearchHit(
                'organizations',
                $c->ulid,
                $c->site->organization->name,
                self::subtitle('Contact phone match', 'Site: '.$c->site->name, $c->site->lga->name.' LGA'),
                '/organizations/'.$c->site->organization->ulid,
                $c->site->classificationLevel(),
                1.0,
                true,
            ))->values()->all());
    }
}
