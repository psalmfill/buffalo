<?php

namespace App\Actions\Blackspots;

use App\Models\Flashpoint;
use App\Support\Jurisdiction\BlackspotClustering;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Regenerates a state's open blackspot suggestions (Phase 6C), in the
 * system context (nightly job, or "Refresh" for SD and above).
 *
 * For each incident cluster (BlackspotClustering):
 * - skipped when an active or monitoring flashpoint's area covers its centre;
 * - skipped when a suggestion within `dismiss_radius_meters` was dismissed in
 *   the last `dismiss_days`;
 * - an open suggestion within that distance is updated in place (keeping its
 *   ULID); otherwise a new one is stored.
 * Open suggestions no longer backed by a cluster are removed: they are
 * derived data, not records. Accepted and dismissed ones are kept.
 */
final class GenerateBlackspotSuggestions
{
    public function __construct(private readonly BlackspotClustering $clustering) {}

    /**
     * @return array{new: int, updated: int, removed: int}
     */
    public function __invoke(int $stateId): array
    {
        $now = CarbonImmutable::now();
        $near = (int) config('buffalo.blackspots.dismiss_radius_meters');
        $minRadius = (int) config('buffalo.blackspots.min_radius_meters');
        $result = ['new' => 0, 'updated' => 0, 'removed' => 0];
        $kept = [];

        DB::transaction(function () use ($stateId, $now, $near, $minRadius, &$result, &$kept): void {
            foreach ($this->clustering->clusters($stateId) as $cluster) {
                $point = [$cluster['longitude'], $cluster['latitude']];

                if ($this->clustering->coveredByFlashpoint($cluster['latitude'], $cluster['longitude'])) {
                    continue;
                }

                $dismissed = DB::table('blackspot_suggestions')
                    ->where('status', 'dismissed')
                    ->where('decided_at', '>=', $now->subDays((int) config('buffalo.blackspots.dismiss_days')))
                    ->whereRaw('ST_DWithin(centroid, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)', [...$point, $near])
                    ->exists();

                if ($dismissed) {
                    continue;
                }

                $values = [
                    'state_id' => $stateId,
                    'lga_id' => $cluster['lga_id'],
                    'centroid' => Flashpoint::pointFrom($cluster['latitude'], $cluster['longitude']),
                    // Whole hundreds of metres, as the flashpoint form's radius slider uses.
                    'radius_meters' => min(50_000, max($minRadius, (int) (ceil($cluster['radius'] / 100) * 100))),
                    'incident_count' => $cluster['count'],
                    'dominant_incident_type_id' => $cluster['dominant_type_id'],
                    'category' => $cluster['category'],
                    'first_occurred_at' => $cluster['first'],
                    'last_occurred_at' => $cluster['last'],
                    'generated_at' => $now,
                ];

                $existing = DB::table('blackspot_suggestions')
                    ->where('state_id', $stateId)
                    ->where('status', 'open')
                    ->whereNotIn('id', $kept)
                    ->whereRaw('ST_DWithin(centroid, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)', [...$point, $near])
                    ->value('id');

                if (is_int($existing)) {
                    DB::table('blackspot_suggestions')->where('id', $existing)->update($values);
                    DB::table('blackspot_suggestion_incidents')->where('blackspot_suggestion_id', $existing)->delete();
                    $id = $existing;
                    $result['updated']++;
                } else {
                    $id = (int) DB::table('blackspot_suggestions')->insertGetId([...$values, 'ulid' => Str::lower((string) Str::ulid()), 'status' => 'open']);
                    $result['new']++;
                }

                DB::table('blackspot_suggestion_incidents')->insert(array_map(fn (int $incident) => [
                    'blackspot_suggestion_id' => $id,
                    'incident_id' => $incident,
                ], $cluster['incident_ids']));
                $kept[] = $id;
            }

            $result['removed'] = DB::table('blackspot_suggestions')
                ->where('state_id', $stateId)->where('status', 'open')->whereNotIn('id', $kept)
                ->delete();
        });

        return $result;
    }
}
