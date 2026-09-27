<?php

namespace App\Support\CrossJurisdiction;

use App\Models\Person;
use Illuminate\Database\Eloquent\Collection;

/**
 * The outcome of a person match (Phase 3C): persons the user can already
 * see (normal references), and hits elsewhere (minimal). $limitReached:
 * the user's daily hard limit stopped hits.
 */
final readonly class MatchResult
{
    /**
     * @param  Collection<int, Person>  $visible
     * @param  list<Hit>  $hits
     */
    public function __construct(
        public Collection $visible,
        public array $hits,
        public bool $limitReached = false,
    ) {}

    /**
     * @return list<array{token: string, state: string, category: string, strength: string}>
     */
    public function hitsArray(): array
    {
        return array_map(fn (Hit $hit) => $hit->toArray(), $this->hits);
    }
}
