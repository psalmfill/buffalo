<?php

namespace App\Support\LocalGovernment;

use App\Models\Community;
use App\Models\Lga;
use App\Models\LgaProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How complete an LGA profile is: the share of these items that are filled
 * in (history, economic activities, terrain, major roads, borders, and at
 * least one community), as a whole percentage.
 *
 * Communities are counted through the jurisdiction scope, so call this in a
 * user (or system) context that covers the LGAs.
 */
final class ProfileCompleteness
{
    public const ITEMS = [
        'history' => 'History',
        'economic_activities' => 'Economic activities',
        'terrain' => 'Terrain',
        'major_roads' => 'Major roads',
        'borders' => 'Borders',
        'communities' => 'At least one community',
    ];

    /**
     * @return list<string> item keys still missing
     */
    public static function missing(?LgaProfile $profile, int $communities): array
    {
        $filled = [
            'history' => self::hasText($profile?->history),
            'economic_activities' => self::hasText($profile?->economic_activities),
            'terrain' => $profile !== null && $profile->terrain !== [],
            'major_roads' => self::hasText($profile?->major_roads),
            'borders' => self::hasText($profile?->borders),
            'communities' => $communities > 0,
        ];

        return array_keys(array_filter($filled, fn (bool $done): bool => ! $done));
    }

    public static function percent(?LgaProfile $profile, int $communities): int
    {
        $total = count(self::ITEMS);

        return (int) round(100 * ($total - count(self::missing($profile, $communities))) / $total);
    }

    /**
     * Completeness, last review and community count for many LGAs at once.
     *
     * @param  iterable<Lga>  $lgas
     * @return Collection<int, array{completeness: int, communities: int, last_reviewed_at: Carbon|null}> keyed by LGA id
     */
    public static function forLgas(iterable $lgas): Collection
    {
        $ids = collect($lgas)->map(fn (Lga $lga): int => $lga->id)->all();

        if ($ids === []) {
            return collect();
        }

        $profiles = LgaProfile::query()->whereIn('lga_id', $ids)->get()->keyBy('lga_id');

        /** @var Collection<int, int> $counts */
        $counts = Community::query()->whereIn('lga_id', $ids)
            ->groupBy('lga_id')
            ->selectRaw('lga_id, count(*) AS total')
            ->pluck('total', 'lga_id')
            ->map(fn (mixed $total): int => (int) $total);

        return collect($ids)->mapWithKeys(function (int $id) use ($profiles, $counts): array {
            /** @var LgaProfile|null $profile */
            $profile = $profiles->get($id);
            $communities = $counts->get($id, 0);

            return [$id => [
                'completeness' => self::percent($profile, $communities),
                'communities' => $communities,
                'last_reviewed_at' => $profile?->last_reviewed_at,
            ]];
        });
    }

    private static function hasText(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
