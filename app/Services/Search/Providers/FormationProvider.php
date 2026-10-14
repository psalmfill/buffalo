<?php

namespace App\Services\Search\Providers;

use App\Enums\Permission;
use App\Models\AgencyFormation;
use App\Models\User;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Agency formations (jurisdiction-scoped and classified): the name by
 * trigram word similarity (`<%`, GIN on search_name).
 */
final class FormationProvider extends BaseProvider
{
    public function key(): string
    {
        return 'formations';
    }

    public function label(): string
    {
        return 'Agency formations';
    }

    protected function permission(): string
    {
        return Permission::FormationsView->value;
    }

    public function hits(SearchQuery $query, User $user, int $limit, int $offset = 0): array
    {
        return array_values($this->query($query)
            ->select('agency_formations.*')
            ->selectRaw('word_similarity(buffalo_search_text(?), agency_formations.search_name) AS search_score', [$query->raw])
            ->with(['agency', 'lga'])
            ->tap(fn (Builder $q) => self::preferStates($q, $query, 'agency_formations.state_id'))
            ->orderByDesc('search_score')
            ->orderBy('agency_formations.name')
            ->offset($offset)->limit($limit)->get()
            ->map(fn (AgencyFormation $f) => new SearchHit(
                'formations',
                $f->ulid,
                $f->name,
                self::subtitle($f->agency->name, $f->formation_type->label(), $f->lga->name.' LGA'),
                '/formations/'.$f->ulid,
                $f->classificationLevel(),
                self::score($f->getAttribute('search_score')),
            ))->all());
    }

    public function count(SearchQuery $query, User $user): int
    {
        return $this->query($query)->count();
    }

    /**
     * @return Builder<AgencyFormation>
     */
    private function query(SearchQuery $query): Builder
    {
        return AgencyFormation::query()->whereRaw('buffalo_search_text(?) <% agency_formations.search_name', [$query->raw]);
    }
}
