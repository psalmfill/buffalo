<?php

namespace App\Services\Search\Providers;

use App\Enums\Permission;
use App\Models\Community;
use App\Models\User;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Communities: their names (generated `search_names`, lowercased and
 * unaccented) by trigram word similarity (`<%`, GIN).
 */
final class CommunityProvider extends BaseProvider
{
    public function key(): string
    {
        return 'communities';
    }

    public function label(): string
    {
        return 'Communities';
    }

    protected function permission(): string
    {
        return Permission::CommunitiesView->value;
    }

    public function hits(SearchQuery $query, User $user, int $limit, int $offset = 0): array
    {
        return array_values($this->query($query)
            ->select('communities.*')
            ->selectRaw('word_similarity(lower(buffalo_unaccent(?)), communities.search_names) AS search_score', [$query->raw])
            ->with(['lga', 'state'])
            ->tap(fn (Builder $q) => self::preferStates($q, $query, 'communities.state_id'))
            ->orderByDesc('search_score')
            ->orderBy('communities.name')
            ->offset($offset)->limit($limit)->get()
            ->map(fn (Community $c) => new SearchHit(
                'communities',
                $c->ulid,
                $c->name,
                self::subtitle($c->type->label(), $c->lga->name.' LGA', $c->state->name),
                '/communities/'.$c->ulid,
                null,
                self::score($c->getAttribute('search_score')),
            ))->all());
    }

    public function count(SearchQuery $query, User $user): int
    {
        return $this->query($query)->count();
    }

    /**
     * @return Builder<Community>
     */
    private function query(SearchQuery $query): Builder
    {
        return Community::query()->whereRaw('lower(buffalo_unaccent(?)) <% communities.search_names', [$query->raw]);
    }
}
