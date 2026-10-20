<?php

namespace App\Support\Arrests;

use App\Http\Presenters\ArrestPresenter;
use App\Models\AgencyFormation;
use App\Models\Arrest;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Arrests on other pages (Phase 11C): a person's, an incident's, an LGA's
 * and a formation's, always through the viewer's own scopes.
 */
final class ArrestLinks
{
    /**
     * @return Builder<Arrest>
     */
    public static function person(Person $person): Builder
    {
        return Arrest::query()->whereHas('persons', fn (Builder $q) => $q->where('arrest_persons.person_id', $person->id));
    }

    /**
     * @return Builder<Arrest>
     */
    public static function incident(Incident $incident): Builder
    {
        return Arrest::query()->where('arrests.incident_id', $incident->id);
    }

    /**
     * @return Builder<Arrest>
     */
    public static function lga(Lga $lga): Builder
    {
        return Arrest::query()->where('arrests.lga_id', $lga->id);
    }

    /**
     * @return Builder<Arrest>
     */
    public static function formation(AgencyFormation $formation): Builder
    {
        return Arrest::query()->where('arrests.agency_formation_id', $formation->id);
    }

    /**
     * @param  Builder<Arrest>  $query
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function rows(Builder $query, string $pageName = 'arrests_page'): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, array<string, mixed>> */
        return $query->with(['agency', 'lga', 'incident', 'persons.offences.offenceType'])
            ->orderByDesc('arrests.arrested_at')->orderByDesc('arrests.id')
            ->paginate(25, pageName: $pageName)->withQueryString()
            ->through(fn (Arrest $a) => ArrestPresenter::row($a));
    }
}
