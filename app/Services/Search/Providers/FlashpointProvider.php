<?php

namespace App\Services\Search\Providers;

use App\Enums\Permission;
use App\Models\Flashpoint;
use App\Models\User;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Flashpoints and blackspots: the name by trigram word similarity (`<%`,
 * GIN on search_name) or the name and description by full text
 * (search_vector, GIN). Score: the better of the two.
 */
final class FlashpointProvider extends BaseProvider
{
    public function key(): string
    {
        return 'flashpoints';
    }

    public function label(): string
    {
        return 'Flashpoints & blackspots';
    }

    protected function permission(): string
    {
        return Permission::FlashpointsView->value;
    }

    public function hits(SearchQuery $query, User $user, int $limit, int $offset = 0): array
    {
        return array_values($this->query($query)
            ->select('flashpoints.*')
            ->selectRaw("GREATEST(word_similarity(buffalo_search_text(?), flashpoints.search_name), ts_rank_cd(flashpoints.search_vector, websearch_to_tsquery('english', ?), 32)) AS search_score", [$query->raw, $query->raw])
            ->with(['threatType', 'lga'])
            ->tap(fn (Builder $q) => self::preferStates($q, $query, 'flashpoints.state_id'))
            ->orderByDesc('search_score')
            ->orderByDesc('flashpoints.last_assessed_at')
            ->offset($offset)->limit($limit)->get()
            ->map(fn (Flashpoint $f) => new SearchHit(
                'flashpoints',
                $f->ulid,
                $f->name,
                self::subtitle($f->kind->label(), $f->threatType->name, $f->concern_level->label().' concern', $f->lga->name.' LGA'),
                '/flashpoints/'.$f->ulid,
                $f->classificationLevel(),
                self::score($f->getAttribute('search_score')),
                false,
                $f->last_assessed_at,
            ))->all());
    }

    public function count(SearchQuery $query, User $user): int
    {
        return $this->query($query)->count();
    }

    /**
     * @return Builder<Flashpoint>
     */
    private function query(SearchQuery $query): Builder
    {
        return Flashpoint::query()->where(fn (Builder $q) => $q
            ->whereRaw('buffalo_search_text(?) <% flashpoints.search_name', [$query->raw])
            ->orWhereRaw("flashpoints.search_vector @@ websearch_to_tsquery('english', ?)", [$query->raw]));
    }
}
