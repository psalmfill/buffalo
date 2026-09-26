<?php

namespace App\Support\Dashboard;

use App\Enums\CriminalGroupStatus;
use App\Enums\CriminalGroupType;
use App\Enums\PoiStatus;
use App\Enums\RiskLevel;
use App\Models\CriminalGroup;
use App\Models\PersonOfInterest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Person-of-interest and criminal-group figures for the dashboards
 * (Phase 3B). All counts go through the scopes (jurisdiction, and the
 * person's or group's classification), so they never include records the
 * viewer could not open.
 *
 * "Open" POIs exclude cleared and deceased ones.
 */
final class ThreatFigures
{
    private const CLOSED = [PoiStatus::Cleared, PoiStatus::Deceased];

    /**
     * @return Builder<PersonOfInterest>
     */
    private function openPois(): Builder
    {
        return PersonOfInterest::query()->whereNotIn('persons_of_interest.status', array_map(fn (PoiStatus $s) => $s->value, self::CLOSED));
    }

    /**
     * @return list<array{value: string, label: string, total: int}>
     */
    public function poisByRisk(?int $lgaId = null): array
    {
        $counts = $this->openPois()
            ->when($lgaId, fn (Builder $q) => $q->where('persons_of_interest.lga_id', $lgaId))
            ->groupBy('persons_of_interest.risk_level')
            ->selectRaw('persons_of_interest.risk_level AS risk, count(*) AS total')
            ->toBase()
            ->pluck('total', 'risk');

        return array_map(fn (RiskLevel $r) => ['value' => $r->value, 'label' => $r->label(), 'total' => (int) ($counts[$r->value] ?? 0)], array_reverse(RiskLevel::cases()));
    }

    public function overdueReviews(?int $lgaId = null): int
    {
        return PersonOfInterest::query()
            ->when($lgaId, fn (Builder $q) => $q->where('persons_of_interest.lga_id', $lgaId))
            ->where('persons_of_interest.review_due_at', '<', CarbonImmutable::today()->toDateString())
            ->count();
    }

    /**
     * Open POIs per LGA or per state, by risk, most first.
     *
     * @param  'lgas'|'states'  $by
     * @return list<array{name: string, total: int, byRisk: array<string, int>}>
     */
    public function poisByPlace(string $by): array
    {
        $column = $by === 'lgas' ? 'lga_id' : 'state_id';

        $rows = $this->openPois()
            ->join($by, "{$by}.id", '=', "persons_of_interest.{$column}")
            ->groupBy("{$by}.name", 'persons_of_interest.risk_level')
            ->selectRaw("{$by}.name AS place, persons_of_interest.risk_level AS risk, count(*) AS total")
            ->toBase()
            ->get();

        return array_values($rows->groupBy('place')->map(fn ($group, $place): array => [
            'name' => (string) $place,
            'total' => (int) $group->sum('total'),
            'byRisk' => $group->mapWithKeys(fn (object $row): array => [(string) $row->risk => (int) $row->total])->all(),
        ])->sortBy([['total', 'desc'], ['name', 'asc']])->values()->all());
    }

    public function activeGroups(): int
    {
        return CriminalGroup::query()->where('status', CriminalGroupStatus::Active->value)->count();
    }

    /**
     * @return list<array{value: string, label: string, total: int}>
     */
    public function activeGroupsByType(): array
    {
        $counts = CriminalGroup::query()
            ->where('criminal_groups.status', CriminalGroupStatus::Active->value)
            ->groupBy('criminal_groups.type')
            ->selectRaw('criminal_groups.type AS type, count(*) AS total')
            ->toBase()
            ->pluck('total', 'type');

        return array_values(array_filter(
            array_map(fn (CriminalGroupType $t) => ['value' => $t->value, 'label' => $t->label(), 'total' => (int) ($counts[$t->value] ?? 0)], CriminalGroupType::cases()),
            fn (array $row) => $row['total'] > 0,
        ));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function riskLevels(): array
    {
        return array_map(fn (RiskLevel $r) => ['value' => $r->value, 'label' => $r->label()], array_reverse(RiskLevel::cases()));
    }
}
