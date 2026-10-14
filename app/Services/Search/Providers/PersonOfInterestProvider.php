<?php

namespace App\Services\Search\Providers;

use App\Enums\Permission;
use App\Enums\PoiStatus;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\User;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Active persons of interest (every status except deceased and cleared),
 * matched like persons. Both the POI record and the person must be visible
 * to the viewer (each through its own scopes). Shows the risk level.
 */
final class PersonOfInterestProvider extends BaseProvider
{
    public function key(): string
    {
        return 'pois';
    }

    public function label(): string
    {
        return 'Persons of interest';
    }

    protected function permission(): string
    {
        return Permission::PoiView->value;
    }

    public function supports(SearchQuery $query): bool
    {
        return $query->isText() || $query->isNumber();
    }

    public function hits(SearchQuery $query, User $user, int $limit, int $offset = 0): array
    {
        $exact = $query->isNumber();
        $persons = PersonProvider::query($this->persons(), $query, scored: true)
            ->with(['lga', 'personOfInterest'])
            ->tap(fn (Builder $q) => self::preferStates($q, $query, 'persons.state_id'))
            ->offset($offset)->limit($limit)->get();

        return array_values($persons->filter(fn (Person $p) => $p->personOfInterest !== null)->map(function (Person $p) use ($exact): SearchHit {
            /** @var PersonOfInterest $poi */
            $poi = $p->personOfInterest;

            return new SearchHit(
                'pois',
                $p->ulid,
                $p->name,
                self::subtitle($poi->risk_level->label().' risk', $poi->status->label(), $p->lga->name.' LGA'),
                '/persons/'.$p->ulid.'?tab=poi',
                $p->classificationLevel(),
                $exact ? 1.0 : self::score($p->getAttribute('name_score')),
                $exact,
                $poi->updated_at,
                $poi->risk_level->value,
            );
        })->values()->all());
    }

    public function count(SearchQuery $query, User $user): int
    {
        return PersonProvider::query($this->persons(), $query, scored: false)->count();
    }

    /**
     * Visible persons with a visible, active POI record.
     *
     * @return Builder<Person>
     */
    private function persons(): Builder
    {
        return Person::query()->whereIn('persons.id', PersonOfInterest::query()
            ->whereIn('persons_of_interest.status', array_map(fn (PoiStatus $s) => $s->value, PoiStatus::active()))
            ->select('persons_of_interest.person_id'));
    }
}
