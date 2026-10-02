<?php

namespace App\Services\Search\Providers;

use App\Enums\Permission;
use App\Models\Incident;
use App\Models\User;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Incidents: an exact `BUF/…` reference (lower(reference_no) index), or the
 * generated `search_vector` with websearch_to_tsquery (GIN). Scored with
 * ts_rank_cd normalisation 32 (rank / (rank + 1), so 0–1).
 *
 * Ranking a broad term over tens of thousands of matches is too slow for
 * the time budget, so results are ranked among the RANK_WINDOW most recent
 * matches the viewer can see (the scoped subquery), which is also the most
 * the results page lists (`max_results`). Counts stay exact.
 */
final class IncidentProvider extends BaseProvider
{
    /** Matches ranked; the results page shows at most this many (max_results). */
    private const RANK_WINDOW = 1000;

    public function key(): string
    {
        return 'incidents';
    }

    public function label(): string
    {
        return 'Incidents';
    }

    protected function permission(): string
    {
        return Permission::IncidentsView->value;
    }

    public function supports(SearchQuery $query): bool
    {
        return $query->isText() || $query->referencePrefix() === 'BUF';
    }

    public function hits(SearchQuery $query, User $user, int $limit, int $offset = 0): array
    {
        return array_values($this->query($query)
            ->with(['type', 'lga'])
            ->orderByDesc('search_score')
            ->orderByDesc('incidents.occurred_at')
            ->offset($offset)->limit($limit)->get()
            ->map(fn (Incident $i) => new SearchHit(
                'incidents',
                $i->ulid,
                $i->title,
                self::subtitle($i->reference_no ?? 'Draft', $i->type->name, $i->lga->name.' LGA', self::ago($i->occurred_at)),
                '/incidents/'.$i->ulid,
                $i->classificationLevel(),
                self::score($i->getAttribute('search_score')),
                $query->reference !== null,
                $i->occurred_at,
            ))->all());
    }

    public function count(SearchQuery $query, User $user): int
    {
        return $this->query($query, scored: false)->count();
    }

    /**
     * @return Builder<Incident>
     */
    private function query(SearchQuery $query, bool $scored = true): Builder
    {
        $base = Incident::query();

        if ($query->reference !== null) {
            return $base->whereRaw('lower(incidents.reference_no) = lower(?)', [$query->reference])
                ->when($scored, fn (Builder $q) => $q->select('incidents.*')->selectRaw('1.0 AS search_score'));
        }

        $matches = fn (): Builder => Incident::query()->whereRaw("incidents.search_vector @@ websearch_to_tsquery('english', ?)", [$query->raw]);

        if (! $scored) {
            return $matches();
        }

        // The window is itself a scoped query (its SQL includes the scopes).
        return $base->whereIn('incidents.id', $matches()->select('incidents.id')->orderByDesc('incidents.occurred_at')->limit(self::RANK_WINDOW))
            ->select('incidents.*')
            ->selectRaw("ts_rank_cd(incidents.search_vector, websearch_to_tsquery('english', ?), 32) AS search_score", [$query->raw]);
    }
}
