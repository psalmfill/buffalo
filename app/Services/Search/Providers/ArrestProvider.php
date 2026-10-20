<?php

namespace App\Services\Search\Providers;

use App\Enums\Permission;
use App\Models\Arrest;
use App\Models\User;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Arrests (Phase 11C): an exact `ARR/…` reference (unique index), or the
 * generated `search_vector` on the plain text copy of the notes (GIN).
 * Results show the agency, LGA, date and status: never names or offences.
 */
final class ArrestProvider extends BaseProvider
{
    public function key(): string
    {
        return 'arrests';
    }

    public function label(): string
    {
        return 'Arrests';
    }

    protected function permission(): string
    {
        return Permission::ArrestsView->value;
    }

    public function supports(SearchQuery $query): bool
    {
        return $query->isText() || $query->referencePrefix() === 'ARR';
    }

    public function hits(SearchQuery $query, User $user, int $limit, int $offset = 0): array
    {
        return array_values($this->query($query)
            ->with(['agency', 'lga'])
            ->tap(fn (Builder $q) => self::preferStates($q, $query, 'arrests.state_id'))
            ->orderByDesc('search_score')
            ->orderByDesc('arrests.arrested_at')
            ->offset($offset)->limit($limit)->get()
            ->map(fn (Arrest $a) => new SearchHit(
                'arrests',
                $a->ulid,
                $a->reference_no,
                self::subtitle($a->agency->short_name, $a->lga->name.' LGA', $a->arrested_at->setTimezone((string) config('app.timezone'))->format('j M Y'), $a->status->label()),
                '/arrests/'.$a->ulid,
                $a->classificationLevel(),
                self::score($a->getAttribute('search_score')),
                $query->reference !== null,
                $a->arrested_at,
            ))->all());
    }

    public function count(SearchQuery $query, User $user): int
    {
        return $this->query($query, scored: false)->count();
    }

    /**
     * @return Builder<Arrest>
     */
    private function query(SearchQuery $query, bool $scored = true): Builder
    {
        $base = Arrest::query();

        if ($query->reference !== null) {
            return $base->where('arrests.reference_no', $query->reference)
                ->when($scored, fn (Builder $q) => $q->select('arrests.*')->selectRaw('1.0 AS search_score'));
        }

        return $base->whereRaw("arrests.search_vector @@ websearch_to_tsquery('english', ?)", [$query->raw])
            ->when($scored, fn (Builder $q) => $q->select('arrests.*')
                ->selectRaw("ts_rank_cd(arrests.search_vector, websearch_to_tsquery('english', ?), 32) AS search_score", [$query->raw]));
    }
}
