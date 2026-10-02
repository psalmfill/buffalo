<?php

namespace App\Services\Search\Providers;

use App\Enums\Permission;
use App\Models\CriminalGroup;
use App\Models\User;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Criminal groups: name and other names (generated search_name) by trigram
 * (`%` / `<%`, GIN).
 */
final class CriminalGroupProvider extends BaseProvider
{
    public function key(): string
    {
        return 'groups';
    }

    public function label(): string
    {
        return 'Criminal groups';
    }

    protected function permission(): string
    {
        return Permission::CriminalGroupsView->value;
    }

    public function hits(SearchQuery $query, User $user, int $limit, int $offset = 0): array
    {
        return array_values($this->query($query)
            ->select('criminal_groups.*')
            ->selectRaw('GREATEST(similarity(criminal_groups.search_name, buffalo_search_text(?)), word_similarity(buffalo_search_text(?), criminal_groups.search_name)) AS search_score', [$query->raw, $query->raw])
            ->with(['lga', 'state'])
            ->orderByDesc('search_score')
            ->orderBy('criminal_groups.name')
            ->offset($offset)->limit($limit)->get()
            ->map(fn (CriminalGroup $g) => new SearchHit(
                'groups',
                $g->ulid,
                $g->name,
                self::subtitle($g->type->label(), $g->status->label(), $g->lga->name.' LGA'),
                '/criminal-groups/'.$g->ulid,
                $g->classificationLevel(),
                self::score($g->getAttribute('search_score')),
                false,
                $g->updated_at,
            ))->all());
    }

    public function count(SearchQuery $query, User $user): int
    {
        return $this->query($query)->count();
    }

    /**
     * @return Builder<CriminalGroup>
     */
    private function query(SearchQuery $query): Builder
    {
        return CriminalGroup::query()->where(fn (Builder $q) => $q
            ->whereRaw('criminal_groups.search_name % buffalo_search_text(?)', [$query->raw])
            ->orWhereRaw('buffalo_search_text(?) <% criminal_groups.search_name', [$query->raw]));
    }
}
