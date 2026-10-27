<?php

namespace App\Services\Search\Providers;

use App\Enums\Permission;
use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\User;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchQuery;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Database\Eloquent\Builder;

/**
 * States (Phase 11F), LGAs and senatorial districts within the viewer's
 * jurisdiction (JurisdictionOptions, as on their own pages). LGAs and
 * districts: the name by trigram (`<%`, GIN on the generated search_name)
 * or the code exactly. States (37 rows, no index needed): the name by
 * substring or word similarity, or the code exactly; only for users who
 * may open state pages.
 */
final class PlaceProvider extends BaseProvider
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'places';
    }

    public function label(): string
    {
        return 'States, LGAs & senatorial districts';
    }

    protected function permission(): string
    {
        return Permission::LgasView->value;
    }

    public function hits(SearchQuery $query, User $user, int $limit, int $offset = 0): array
    {
        $take = $offset + $limit;
        $lgas = $this->lgas($query, $user)
            ->select('lgas.*')
            ->selectRaw('CASE WHEN upper(lgas.code) = upper(?) THEN 1.0 ELSE word_similarity(buffalo_search_text(?), lgas.search_name) END AS search_score', [$query->raw, $query->raw])
            ->with('state')
            ->tap(fn (Builder $q) => self::preferStates($q, $query, 'lgas.state_id'))
            ->orderByDesc('search_score')->orderBy('lgas.name')
            ->limit($take)->get()
            ->map(fn (Lga $l) => new SearchHit(
                'places',
                $l->ulid,
                $l->name.' LGA',
                self::subtitle($l->state->name.' State', $l->code),
                '/lgas/'.$l->ulid,
                null,
                self::score($l->getAttribute('search_score')),
            ));
        $districts = $this->districts($query, $user)
            ->select('senatorial_districts.*')
            ->selectRaw('CASE WHEN upper(senatorial_districts.code) = upper(?) THEN 1.0 ELSE word_similarity(buffalo_search_text(?), senatorial_districts.search_name) END AS search_score', [$query->raw, $query->raw])
            ->with('state')
            ->tap(fn (Builder $q) => self::preferStates($q, $query, 'senatorial_districts.state_id'))
            ->orderByDesc('search_score')->orderBy('senatorial_districts.name')
            ->limit($take)->get()
            ->map(fn (SenatorialDistrict $d) => new SearchHit(
                'places',
                $d->ulid,
                $d->name,
                self::subtitle('Senatorial district', $d->state->name.' State', $d->code),
                '/senatorial-districts/'.$d->ulid,
                null,
                self::score($d->getAttribute('search_score')),
            ));

        $states = $this->states($query, $user)
            ->select('states.*')
            ->selectRaw('CASE WHEN upper(states.code) = upper(?) THEN 1.0 ELSE word_similarity(buffalo_search_text(?), buffalo_search_text(states.name)) END AS search_score', [$query->raw, $query->raw])
            ->tap(fn (Builder $q) => self::preferStates($q, $query, 'states.id'))
            ->orderByDesc('search_score')->orderBy('states.name')
            ->limit($take)->get()
            ->map(fn (State $s) => new SearchHit(
                'places',
                $s->ulid,
                $s->name.' State',
                self::subtitle('State', $s->code),
                '/states/'.$s->ulid,
                null,
                // A state outranks its namesake LGA (Kano State before Kano Municipal).
                self::score((float) $s->getAttribute('search_score') + 0.01),
            ));

        return array_slice(SearchHit::rank([...$states->all(), ...$lgas->all(), ...$districts->all()]), $offset, $limit);
    }

    public function count(SearchQuery $query, User $user): int
    {
        return $this->states($query, $user)->count() + $this->lgas($query, $user)->count() + $this->districts($query, $user)->count();
    }

    /**
     * @return Builder<State>
     */
    private function states(SearchQuery $query, User $user): Builder
    {
        if (! $user->can('viewAny', State::class)) {
            return State::query()->whereRaw('1 = 0');
        }

        return $this->options->stateQuery($user)->active()->where(fn (Builder $q) => $q
            ->whereRaw('strpos(buffalo_search_text(states.name), buffalo_search_text(?)) > 0', [$query->raw])
            ->orWhereRaw('word_similarity(buffalo_search_text(?), buffalo_search_text(states.name)) >= 0.6', [$query->raw])
            ->orWhereRaw('upper(states.code) = upper(?)', [$query->raw]));
    }

    /**
     * @return Builder<Lga>
     */
    private function lgas(SearchQuery $query, User $user): Builder
    {
        return $this->options->lgaQuery($user)->active()->where(fn (Builder $q) => $q
            ->whereRaw('buffalo_search_text(?) <% lgas.search_name', [$query->raw])
            ->orWhereRaw('upper(lgas.code) = upper(?)', [$query->raw]));
    }

    /**
     * @return Builder<SenatorialDistrict>
     */
    private function districts(SearchQuery $query, User $user): Builder
    {
        return $this->options->districtQuery($user)->active()->where(fn (Builder $q) => $q
            ->whereRaw('buffalo_search_text(?) <% senatorial_districts.search_name', [$query->raw])
            ->orWhereRaw('upper(senatorial_districts.code) = upper(?)', [$query->raw]));
    }
}
