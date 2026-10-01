<?php

namespace App\Support\Map;

use App\Enums\SecurityRelevance;
use App\Enums\ThreatStatus;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\Flashpoint;
use App\Models\OrganizationSite;
use App\Models\ThreatReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Point layers of the operational map (Phase 6C): flashpoints, threat
 * reports, formations, organization sites and communities inside the
 * requested bounding box, through each module's own scopes. Properties are
 * what the existing popups show (no descriptions), plus a link.
 */
final class PointLayers
{
    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox
     * @return array<string, mixed>
     */
    public function flashpoints(array $bbox): array
    {
        $rows = $this->within(Flashpoint::query()->withCoordinates()->with('threatType'), 'flashpoints.location', $bbox)->byConcern()->get();

        return $this->collection($rows, fn (Flashpoint $f) => [
            'ulid' => $f->ulid,
            'name' => $f->name,
            'kind' => $f->kind->label(),
            'threat_type' => $f->threatType->name,
            'concern' => $f->concern_level->value,
            'concern_label' => $f->concern_level->label(),
            'status' => $f->status->value,
            'radius_meters' => $f->radius_meters,
            'href' => "/flashpoints/{$f->ulid}",
        ]);
    }

    /**
     * Threat reports (active only unless $all): points with radius and
     * urgency; reports without a point are drawn at their community, or
     * as their LGA's outline (or centroid) when they cover the whole LGA.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox
     * @param  list<string>|null  $urgencies
     * @return array<string, mixed>
     */
    public function threats(array $bbox, bool $all, ?array $urgencies, string $boundaryZoom): array
    {
        $base = fn () => ThreatReport::query()
            ->with(['threatType', 'lga'])
            ->when(! $all, fn (Builder $q) => $q->where('threat_reports.status', ThreatStatus::Active->value))
            ->when($urgencies !== null, fn (Builder $q) => $q->whereIn('threat_reports.urgency', $urgencies ?? []));

        $located = $this->within($base()->withCoordinates(), 'threat_reports.location', $bbox)->byUrgency()->get();

        // Without a point: the community's location, else the LGA's outline or centroid.
        $area = $base()->whereNull('threat_reports.location')
            ->leftJoin('communities', 'communities.id', '=', 'threat_reports.community_id')
            ->leftJoin('boundary_simplified AS b', fn ($j) => $j->on('b.place_id', '=', 'threat_reports.lga_id')->where('b.place_type', 'lga')->where('b.zoom', $boundaryZoom))
            ->join('lgas', 'lgas.id', '=', 'threat_reports.lga_id')
            ->select('threat_reports.*')
            ->selectRaw('ST_AsGeoJSON(CASE WHEN communities.location IS NOT NULL THEN communities.location::geometry WHEN b.geom IS NOT NULL THEN b.geom ELSE lgas.centroid::geometry END, 5) AS area_geometry')
            ->whereRaw('COALESCE(communities.location::geometry, b.geom, lgas.centroid::geometry) && ST_MakeEnvelope(?, ?, ?, ?, 4326)', $bbox)
            ->byUrgency()
            ->limit((int) config('buffalo.map.max_features'))
            ->get();

        $properties = fn (ThreatReport $t) => [
            'ulid' => $t->ulid,
            'reference_no' => $t->reference_no,
            'title' => $t->title,
            'threat_type' => $t->threatType->name,
            'urgency' => $t->urgency->value,
            'urgency_label' => $t->urgency->label(),
            'credibility' => $t->credibility->label(),
            'status' => $t->status->value,
            'radius_meters' => $t->radius_meters,
            'area' => $t->location === null ? ($t->community_id !== null ? 'community' : 'lga') : 'point',
            'lga' => $t->lga->name,
            'href' => "/threats/{$t->ulid}",
        ];

        $features = [
            ...$this->collection($located, $properties)['features'],
            ...array_map(fn (ThreatReport $t) => [
                'type' => 'Feature',
                'geometry' => json_decode((string) $t->getAttribute('area_geometry'), true),
                'properties' => $properties($t),
            ], $area->all()),
        ];

        return ['type' => 'FeatureCollection', 'features' => array_values(array_filter($features, fn (array $f) => $f['geometry'] !== null)), 'meta' => []];
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox
     * @return array<string, mixed>
     */
    public function formations(array $bbox): array
    {
        $rows = $this->within(AgencyFormation::query()->withCoordinates()->with('agency'), 'agency_formations.location', $bbox)->orderBy('agency_formations.name')->get();

        return $this->collection($rows, fn (AgencyFormation $f) => [
            'ulid' => $f->ulid,
            'name' => $f->name,
            'agency' => $f->agency->short_name ?: $f->agency->name,
            'type' => $f->formation_type->label(),
            'href' => "/formations/{$f->ulid}",
        ]);
    }

    /**
     * Organization sites: high and critical-infrastructure relevance unless $all.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox
     * @return array<string, mixed>
     */
    public function sites(array $bbox, bool $all): array
    {
        $rows = $this->within(OrganizationSite::query()->withCoordinates()->with('organization'), 'organization_sites.location', $bbox)
            ->when(! $all, fn (Builder $q) => $q->whereIn('organization_sites.security_relevance', [SecurityRelevance::High->value, SecurityRelevance::CriticalInfrastructure->value]))
            ->orderBy('organization_sites.name')->get();

        return $this->collection($rows, fn (OrganizationSite $s) => [
            'ulid' => $s->ulid,
            'name' => $s->name,
            'organization' => $s->organization->name,
            'relevance' => $s->security_relevance->value,
            'relevance_label' => $s->security_relevance->label(),
            'href' => "/organization-sites/{$s->ulid}",
        ]);
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox
     * @return array<string, mixed>
     */
    public function communities(array $bbox): array
    {
        $rows = $this->within(Community::query()->withCoordinates(), 'communities.location', $bbox)->orderBy('communities.name')->get();

        return $this->collection($rows, fn (Community $c) => [
            'ulid' => $c->ulid,
            'name' => $c->name,
            'href' => "/communities/{$c->ulid}",
        ]);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $column
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox
     * @return Builder<TModel>
     */
    private function within(Builder $query, string $column, array $bbox): Builder
    {
        return $query->whereNotNull($column)
            ->whereRaw($column.' && ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography', $bbox)
            ->limit((int) config('buffalo.map.max_features'));
    }

    /**
     * Point features from rows with `latitude`/`longitude` attributes.
     *
     * @template TModel of Model
     *
     * @param  iterable<TModel>  $rows
     * @param  callable(TModel): array<string, mixed>  $properties
     * @return array{type: string, features: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function collection(iterable $rows, callable $properties): array
    {
        $features = [];

        foreach ($rows as $row) {
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [round((float) $row->getAttribute('longitude'), 5), round((float) $row->getAttribute('latitude'), 5)]],
                'properties' => $properties($row),
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features, 'meta' => ['capped' => count($features) >= (int) config('buffalo.map.max_features')]];
    }
}
