<?php

namespace App\Http\Controllers\Map;

use App\Enums\Permission;
use App\Enums\ThreatUrgency;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BlackspotSuggestionRows;
use App\Models\AgencyFormation;
use App\Models\BlackspotSuggestion;
use App\Models\Community;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\OrganizationSite;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Map\IncidentLayers;
use App\Support\Map\MapFilters;
use App\Support\Map\PointLayers;
use App\Support\Map\ZoomBucket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Operational map layers (Phase 6C), as GeoJSON. Each needs map.view and
 * the layer's own module view permission, and is computed through the
 * viewer's scopes. Aggregated incident layers are cached per scope
 * signature (IncidentLayers / LayerCache).
 */
class MapLayerController extends Controller
{
    public function density(Request $request, IncidentLayers $layers): JsonResponse
    {
        $user = $this->authorizeLayer($request, Incident::class);
        [$bbox, $zoom] = $this->view($request);

        return $this->json($layers->density($user, MapFilters::from($request->query()), $bbox, $zoom));
    }

    public function lgaChoropleth(Request $request, IncidentLayers $layers): JsonResponse
    {
        $user = $this->authorizeLayer($request, Incident::class);
        [$bbox, $zoom] = $this->view($request);

        return $this->json($layers->choropleth($user, MapFilters::from($request->query()), 'lga', $bbox, $zoom, $request->query('measure') === 'rate'));
    }

    public function stateChoropleth(Request $request, IncidentLayers $layers): JsonResponse
    {
        $user = $this->authorizeLayer($request, Incident::class);
        [$bbox, $zoom] = $this->view($request);

        return $this->json($layers->choropleth($user, MapFilters::from($request->query()), 'state', $bbox, $zoom, $request->query('measure') === 'rate'));
    }

    public function flashpoints(Request $request, PointLayers $layers): JsonResponse
    {
        $this->authorizeLayer($request, Flashpoint::class);

        return $this->json($layers->flashpoints($this->view($request)[0]));
    }

    public function threats(Request $request, PointLayers $layers): JsonResponse
    {
        $this->authorizeLayer($request, ThreatReport::class);
        [$bbox, $zoom] = $this->view($request);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'any'])],
            'urgencies' => ['nullable', 'array'],
            'urgencies.*' => [Rule::enum(ThreatUrgency::class)],
        ]);

        /** @var list<string>|null $urgencies */
        $urgencies = isset($data['urgencies']) ? array_values($data['urgencies']) : null;

        return $this->json($layers->threats($bbox, ($data['status'] ?? 'active') === 'any', $urgencies, ZoomBucket::forZoom($zoom)->boundaryZoom()));
    }

    public function formations(Request $request, PointLayers $layers): JsonResponse
    {
        $this->authorizeLayer($request, AgencyFormation::class);

        return $this->json($layers->formations($this->view($request)[0]));
    }

    public function sites(Request $request, PointLayers $layers): JsonResponse
    {
        $this->authorizeLayer($request, OrganizationSite::class);

        return $this->json($layers->sites($this->view($request)[0], $request->query('relevance') === 'all'));
    }

    public function communities(Request $request, PointLayers $layers): JsonResponse
    {
        $this->authorizeLayer($request, Community::class);

        return $this->json($layers->communities($this->view($request)[0]));
    }

    /** Open suggested blackspots the viewer may see (counts through their scopes). */
    public function suggestions(Request $request): JsonResponse
    {
        $this->authorizeLayer($request, BlackspotSuggestion::class);
        [$bbox] = $this->view($request);

        $rows = BlackspotSuggestionRows::rows(BlackspotSuggestion::query()
            ->where('blackspot_suggestions.status', 'open')
            ->whereRaw('blackspot_suggestions.centroid && ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography', $bbox));

        return $this->json([
            'type' => 'FeatureCollection',
            'features' => array_map(fn (array $r) => [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [round((float) $r['longitude'], 5), round((float) $r['latitude'], 5)]],
                'properties' => [
                    'ulid' => $r['ulid'],
                    'incident_count' => $r['incident_count'],
                    'radius_meters' => $r['radius_meters'],
                    'dominant_type' => $r['dominant_type'],
                    'lga' => $r['lga']['name'],
                    'href' => '/blackspot-suggestions?open='.$r['ulid'],
                ],
            ], $rows),
            'meta' => [],
        ]);
    }

    /**
     * @param  class-string  $model
     */
    private function authorizeLayer(Request $request, string $model): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->checkPermissionTo(Permission::MapView->value), 403);
        $this->authorize('viewAny', $model);

        return $user;
    }

    /**
     * The requested bounding box (west,south,east,north; clamped around
     * Nigeria) and zoom.
     *
     * @return array{0: array{0: float, 1: float, 2: float, 3: float}, 1: int}
     */
    private function view(Request $request): array
    {
        $data = $request->validate([
            'bbox' => ['required', 'string', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/'],
            'zoom' => ['required', 'integer', 'between:3,20'],
        ]);
        [$w, $s, $e, $n] = array_map('floatval', explode(',', (string) $data['bbox']));
        $bbox = [max($w, 0.0), max($s, 2.0), min($e, 17.0), min($n, 16.0)];
        abort_if($bbox[0] >= $bbox[2] || $bbox[1] >= $bbox[3], 422, 'The bounding box is outside the map.');

        return [$bbox, (int) $data['zoom']];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
