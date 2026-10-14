<?php

namespace App\Services\Search\Providers;

use App\Enums\Permission;
use App\Models\Person;
use App\Models\User;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchQuery;
use App\Support\Persons\PersonSearch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Persons the viewer may see (own jurisdiction, active grants,
 * classification): name and aliases by trigram (`%` / `<%`, GIN), or an
 * exact NIN or phone through the blind indexes.
 *
 * NEVER cross-jurisdiction: this is the scoped Person query only.
 * PersonMatchService (hit/no-hit, record_match_events) is not used; the
 * results page links to /persons/search for that.
 */
final class PersonProvider extends BaseProvider
{
    public function key(): string
    {
        return 'persons';
    }

    public function label(): string
    {
        return 'Persons';
    }

    protected function permission(): string
    {
        return Permission::PersonsView->value;
    }

    public function supports(SearchQuery $query): bool
    {
        return $query->isText() || $query->isNumber();
    }

    public function hits(SearchQuery $query, User $user, int $limit, int $offset = 0): array
    {
        $exact = $query->isNumber();
        $rows = $this->query(Person::query(), $query, scored: true)
            ->with(['lga', 'state'])
            ->tap(fn (Builder $q) => self::preferStates($q, $query, 'persons.state_id'))
            ->offset($offset)->limit($limit)->get();

        return array_values($rows->map(fn (Person $p) => new SearchHit(
            'persons',
            $p->ulid,
            $p->name,
            self::subtitle($p->lga->name.' LGA', $p->state->name, $exact ? ($query->nin !== null ? 'NIN or phone match' : 'Phone match') : null),
            '/persons/'.$p->ulid,
            $p->classificationLevel(),
            $exact ? 1.0 : self::score($p->getAttribute('name_score')),
            $exact,
            $p->updated_at,
        ))->all());
    }

    public function count(SearchQuery $query, User $user): int
    {
        return $this->query(Person::query(), $query, scored: false)->count();
    }

    /**
     * Name or number matches on a scoped Person query.
     *
     * @param  Builder<Person>  $base
     * @return Builder<Person>
     */
    public static function query(Builder $base, SearchQuery $query, bool $scored): Builder
    {
        if ($query->isNumber()) {
            return PersonSearch::byNumber($base, $query->personTerm());
        }

        return $scored
            ? PersonSearch::byName($base, $query->raw)
            : PersonSearch::whereNameMatches($base, $query->raw);
    }
}
