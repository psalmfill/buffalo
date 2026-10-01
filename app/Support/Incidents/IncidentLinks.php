<?php

namespace App\Support\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\PersonInvolvement;
use App\Http\Presenters\IncidentRows;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\OrganizationSite;
use App\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Incidents linked to other records (Phase 5D): the Incidents tab on
 * persons, criminal groups, formations, organization sites, communities and
 * LGAs. Every query starts from the scoped incident query, so the tab lists
 * only incidents the viewer may see; drafts and invalid reports are left
 * out (statistics rule). The tab is hidden when the query is empty.
 */
final class IncidentLinks
{
    public const PAGE = 'incidents_page';

    /** @return Builder<Incident> */
    public static function person(Person $person): Builder
    {
        return self::linkedThrough('incident_persons', 'person_id', $person->id);
    }

    /** @return Builder<Incident> */
    public static function group(CriminalGroup $group): Builder
    {
        return self::linkedThrough('incident_criminal_groups', 'criminal_group_id', $group->id);
    }

    /** @return Builder<Incident> */
    public static function formation(AgencyFormation $formation): Builder
    {
        return self::linkedThrough('incident_agencies', 'agency_formation_id', $formation->id);
    }

    /** @return Builder<Incident> */
    public static function site(OrganizationSite $site): Builder
    {
        return self::linkedThrough('incident_properties', 'organization_site_id', $site->id);
    }

    /** @return Builder<Incident> */
    public static function community(Community $community): Builder
    {
        return self::base()->where('incidents.community_id', $community->id);
    }

    /** @return Builder<Incident> */
    public static function lga(Lga $lga): Builder
    {
        return self::base()->where('incidents.lga_id', $lga->id);
    }

    /**
     * One page of list rows, newest first.
     *
     * @param  Builder<Incident>  $query
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function rows(Builder $query): LengthAwarePaginator
    {
        $page = $query->with(['type', 'lga', 'state'])
            ->orderByDesc('incidents.occurred_at')->orderByDesc('incidents.id')
            ->paginate(25, pageName: self::PAGE)
            ->withQueryString();
        $page->setCollection(collect(IncidentRows::present($page->items())));

        /** @var LengthAwarePaginator<int, array<string, mixed>> */
        return $page;
    }

    /**
     * The person's involvement in each listed incident (e.g. "Suspect").
     *
     * @param  list<string>  $incidentUlids
     * @return array<string, string> incident ULID => involvement labels
     */
    public static function involvements(Person $person, array $incidentUlids): array
    {
        return DB::table('incident_persons')
            ->join('incidents', 'incidents.id', '=', 'incident_persons.incident_id')
            ->whereIn('incidents.ulid', $incidentUlids)
            ->where('incident_persons.person_id', $person->id)
            ->whereNull('incident_persons.deleted_at')
            ->get(['incidents.ulid', 'incident_persons.involvement'])
            ->groupBy('ulid')
            ->map(fn ($rows) => $rows->map(fn (object $r) => PersonInvolvement::from($r->involvement)->label())->unique()->implode(', '))
            ->all();
    }

    /**
     * The LGA's incidents in the last 90 days, by type (largest first).
     *
     * @return list<array{type: string, count: int}>
     */
    public static function lgaSummary(Lga $lga): array
    {
        return array_values(self::lga($lga)
            ->where('incidents.occurred_at', '>=', CarbonImmutable::now()->subDays(90))
            ->join('incident_types', 'incident_types.id', '=', 'incidents.incident_type_id')
            ->groupBy('incident_types.name')
            ->orderByRaw('COUNT(*) DESC')
            ->get([DB::raw('incident_types.name AS type'), DB::raw('COUNT(*) AS count')])
            ->map(fn (Incident $row) => ['type' => (string) $row->getAttribute('type'), 'count' => (int) $row->getAttribute('count')])
            ->all());
    }

    /** @return Builder<Incident> */
    private static function base(): Builder
    {
        return Incident::query()->whereNotIn('incidents.status', [IncidentStatus::Draft->value, IncidentStatus::Invalid->value]);
    }

    /** @return Builder<Incident> */
    private static function linkedThrough(string $table, string $column, int $id): Builder
    {
        return self::base()->whereExists(fn ($q) => $q->select(DB::raw(1))->from($table)
            ->whereColumn("{$table}.incident_id", 'incidents.id')
            ->whereNull("{$table}.deleted_at")
            ->where("{$table}.{$column}", $id));
    }
}
