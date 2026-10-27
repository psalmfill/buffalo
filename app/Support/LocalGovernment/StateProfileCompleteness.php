<?php

namespace App\Support\LocalGovernment;

use App\Models\LgaProfile;
use App\Models\State;
use App\Models\StateProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How complete a state profile is (Phase 11F), like the LGA profile's: the
 * share of these items that are filled in (history, economic activities,
 * terrain, major roads, borders, and at least one of the state's LGA
 * profiles reviewed), as a whole percentage.
 *
 * LGA profiles are counted through the jurisdiction scope, so call this in
 * a user (or system) context. An LGA chief's figure therefore counts only
 * their own LGA's profile for the last item.
 */
final class StateProfileCompleteness
{
    public const ITEMS = [
        'history' => 'History',
        'economic_activities' => 'Economic activities',
        'terrain' => 'Terrain',
        'major_roads' => 'Major roads',
        'borders' => 'Borders',
        'lga_profiles' => 'At least one LGA profile reviewed',
    ];

    /**
     * @return list<string> item keys still missing
     */
    public static function missing(?StateProfile $profile, int $reviewedLgaProfiles): array
    {
        $filled = [
            'history' => self::hasText($profile?->history),
            'economic_activities' => self::hasText($profile?->economic_activities),
            'terrain' => $profile !== null && $profile->terrain !== [],
            'major_roads' => self::hasText($profile?->major_roads),
            'borders' => self::hasText($profile?->borders),
            'lga_profiles' => $reviewedLgaProfiles > 0,
        ];

        return array_keys(array_filter($filled, fn (bool $done): bool => ! $done));
    }

    public static function percent(?StateProfile $profile, int $reviewedLgaProfiles): int
    {
        $total = count(self::ITEMS);

        return (int) round(100 * ($total - count(self::missing($profile, $reviewedLgaProfiles))) / $total);
    }

    /**
     * @return array{percent: int, missing: list<string>}
     */
    public static function summary(?StateProfile $profile, int $reviewedLgaProfiles): array
    {
        return [
            'percent' => self::percent($profile, $reviewedLgaProfiles),
            'missing' => array_map(fn (string $item): string => self::ITEMS[$item], self::missing($profile, $reviewedLgaProfiles)),
        ];
    }

    /** LGA profiles of the state that have been reviewed (through the scope). */
    public static function reviewedLgaProfiles(State $state): int
    {
        return LgaProfile::query()->where('state_id', $state->id)->whereNotNull('last_reviewed_at')->count();
    }

    /**
     * Completeness and last review for many states at once.
     *
     * @param  iterable<State>  $states
     * @return Collection<int, array{completeness: int, last_reviewed_at: Carbon|null}> keyed by state id
     */
    public static function forStates(iterable $states): Collection
    {
        $ids = collect($states)->map(fn (State $state): int => $state->id)->all();

        if ($ids === []) {
            return collect();
        }

        $profiles = StateProfile::query()->whereIn('state_id', $ids)->get()->keyBy('state_id');

        /** @var Collection<int, int> $reviewed */
        $reviewed = LgaProfile::query()->whereIn('state_id', $ids)->whereNotNull('last_reviewed_at')
            ->groupBy('state_id')
            ->selectRaw('state_id, count(*) AS total')
            ->pluck('total', 'state_id')
            ->map(fn (mixed $total): int => (int) $total);

        return collect($ids)->mapWithKeys(function (int $id) use ($profiles, $reviewed): array {
            /** @var StateProfile|null $profile */
            $profile = $profiles->get($id);

            return [$id => [
                'completeness' => self::percent($profile, $reviewed->get($id, 0)),
                'last_reviewed_at' => $profile?->last_reviewed_at,
            ]];
        });
    }

    private static function hasText(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
