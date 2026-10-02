<?php

namespace App\Services\Search\Providers;

use App\Enums\Permission;
use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\User;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchQuery;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Database\Eloquent\Builder;

/**
 * LGAs and senatorial districts within the viewer's jurisdiction
 * (JurisdictionOptions, as on their own pages): the name by trigram
 * (`<%`, GIN on the generated search_name) or the code exactly.
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
        return 'LGAs & senatorial districts';
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

        return array_slice(SearchHit::rank([...$lgas->all(), ...$districts->all()]), $offset, $limit);
    }

    public function count(SearchQuery $query, User $user): int
    {
        return $this->lgas($query, $user)->count() + $this->districts($query, $user)->count();
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
