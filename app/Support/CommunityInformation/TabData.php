<?php

namespace App\Support\CommunityInformation;

use App\Enums\VulnerableCategory;
use App\Http\Presenters\CommunityInformationPresenter;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use App\Models\Lga;
use App\Models\Religion;
use App\Models\ReligiousInstitution;
use App\Models\VulnerablePopulationStat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Data for the religious institutions, vulnerable population and security
 * notes sections, for an LGA page or a community page. Every query goes
 * through the jurisdiction scope (and, for notes, the classification
 * scope) of the signed-in user.
 */
final class TabData
{
    /** Map markers per page (plain markers, no clustering). */
    public const MARKER_LIMIT = 1000;

    /**
     * @return array<string, mixed>
     */
    public function religiousInstitutions(Lga $lga, ?Community $community, Request $request): array
    {
        $filters = $request->validate([
            'religion' => ['nullable', 'string', 'size:26'],
            'type' => ['nullable', 'string', Rule::in(array_column(CommunityInformationPresenter::options()['institutionTypes'], 'value'))],
            'community' => ['nullable', 'string', 'size:26'],
        ]);

        $base = fn (): Builder => ReligiousInstitution::query()
            ->where('religious_institutions.lga_id', $lga->id)
            ->when($community, fn (Builder $query) => $query->where('religious_institutions.community_id', $community?->id));

        $list = $base()
            ->with(['religion', 'denomination', 'community'])
            ->withCoordinates()
            ->when($filters['religion'] ?? null, fn (Builder $query, string $religion) => $query->whereHas('religion', fn (Builder $q) => $q->where('ulid', $religion)))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('religious_institutions.type', $type))
            ->when(! $community ? ($filters['community'] ?? null) : null, fn (Builder $query, string $ulid) => $query->whereHas('community', fn (Builder $q) => $q->where('ulid', $ulid)))
            ->orderBy('religious_institutions.name')
            ->paginate(25, pageName: 'institutions_page')
            ->withQueryString();

        $byReligion = $base()->join('religions', 'religions.id', '=', 'religious_institutions.religion_id')
            ->groupBy('religions.name')->orderBy('religions.name')
            ->selectRaw('religions.name AS name, count(*) AS total')->toBase()->get()
            ->map(fn (object $row): array => ['label' => (string) $row->name, 'count' => (int) $row->total])->all();

        $typeLabels = collect(CommunityInformationPresenter::options()['institutionTypes'])->pluck('label', 'value');
        $byType = $base()->groupBy('religious_institutions.type')->orderBy('religious_institutions.type')
            ->selectRaw('religious_institutions.type AS type, count(*) AS total')->toBase()->get()
            ->map(fn (object $row): array => ['label' => (string) $typeLabels->get((string) $row->type), 'count' => (int) $row->total])->all();

        return [
            'list' => $list->through(fn (ReligiousInstitution $institution) => CommunityInformationPresenter::institution($institution)),
            'filters' => [
                'religion' => $filters['religion'] ?? '',
                'type' => $filters['type'] ?? '',
                'community' => $filters['community'] ?? '',
            ],
            'summary' => ['total' => $list->total(), 'byReligion' => $byReligion, 'byType' => $byType],
            'religions' => Religion::query()->orderBy('sort_order')->orderBy('name')->get()->map(fn (Religion $religion) => ['value' => $religion->ulid, 'label' => $religion->name]),
            'markers' => $this->institutionMarkers($lga, $community),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function institutionMarkers(Lga $lga, ?Community $community = null): array
    {
        return array_values(ReligiousInstitution::query()
            ->with(['religion', 'community'])
            ->withCoordinates()
            ->where('religious_institutions.lga_id', $lga->id)
            ->when($community, fn (Builder $query) => $query->where('religious_institutions.community_id', $community?->id))
            ->whereNotNull('religious_institutions.location')
            ->orderBy('religious_institutions.name')
            ->limit(self::MARKER_LIMIT)
            ->get()
            ->map(fn (ReligiousInstitution $institution) => CommunityInformationPresenter::institutionMarker($institution))
            ->filter()
            ->all());
    }

    /**
     * Latest figures per category (LGA-wide on an LGA page, the
     * community's on a community page), with totals; on an LGA page also
     * the latest figure of each community; and one category's history.
     *
     * @return array<string, mixed>
     */
    public function vulnerablePopulation(Lga $lga, ?Community $community, Request $request): array
    {
        $filters = $request->validate([
            'history' => ['nullable', Rule::enum(VulnerableCategory::class)],
        ]);

        $latest = $this->latestFigures($lga, $community);

        $byCommunity = $community === null
            ? VulnerablePopulationStat::query()->latestPerSeries()->with('community')
                ->where('vulnerable_population_stats.lga_id', $lga->id)
                ->whereNotNull('vulnerable_population_stats.community_id')
                ->get()
                ->sortBy(fn (VulnerablePopulationStat $figure) => ($figure->community->name ?? '').' '.$figure->category->label())
                ->map(fn (VulnerablePopulationStat $figure) => CommunityInformationPresenter::figure($figure))
                ->values()
                ->all()
            : [];

        $history = isset($filters['history'])
            ? VulnerablePopulationStat::query()->with('community')
                ->where('lga_id', $lga->id)
                ->where('community_id', $community?->id)
                ->where('category', $filters['history'])
                ->orderByDesc('as_of_date')
                ->get()
                ->map(fn (VulnerablePopulationStat $figure) => CommunityInformationPresenter::figure($figure))
                ->all()
            : null;

        return [
            'latest' => $latest->map(fn (VulnerablePopulationStat $figure) => CommunityInformationPresenter::figure($figure))->values()->all(),
            'totals' => self::totals($latest),
            'byCommunity' => $byCommunity,
            'history' => $history,
            'historyCategory' => $filters['history'] ?? null,
        ];
    }

    /**
     * The latest figure per category for the LGA (LGA-wide rows) or for one
     * community.
     *
     * @return Collection<int, VulnerablePopulationStat>
     */
    public function latestFigures(Lga $lga, ?Community $community): Collection
    {
        return VulnerablePopulationStat::query()->latestPerSeries()->with('community')
            ->where('vulnerable_population_stats.lga_id', $lga->id)
            ->where('vulnerable_population_stats.community_id', $community?->id)
            ->get()
            ->sortBy(fn (VulnerablePopulationStat $figure) => array_search($figure->category, VulnerableCategory::cases(), true))
            ->values();
    }

    /**
     * @param  iterable<VulnerablePopulationStat>  $figures
     * @return array{adult_male: int, adult_female: int, child_male: int, child_female: int, total: int}
     */
    public static function totals(iterable $figures): array
    {
        $totals = ['adult_male' => 0, 'adult_female' => 0, 'child_male' => 0, 'child_female' => 0, 'total' => 0];

        foreach ($figures as $figure) {
            foreach (VulnerablePopulationStat::COUNTS as $count) {
                $totals[$count] += $figure->{$count};
            }
            $totals['total'] += $figure->total();
        }

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    public function securityNotes(Lga $lga, ?Community $community, Request $request): array
    {
        $options = CommunityInformationPresenter::options();
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_column($options['noteStatuses'], 'value'))],
            'category' => ['nullable', 'string', Rule::in(array_column($options['noteCategories'], 'value'))],
        ]);

        $notes = CommunitySecurityNote::query()
            ->with('community')
            ->where('community_security_notes.lga_id', $lga->id)
            ->when($community, fn (Builder $query) => $query->where('community_security_notes.community_id', $community?->id))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('community_security_notes.status', $status))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('community_security_notes.category', $category))
            ->orderByRaw("CASE community_security_notes.status WHEN 'active' THEN 0 WHEN 'monitoring' THEN 1 ELSE 2 END")
            ->orderByDesc('community_security_notes.observed_at')
            ->paginate(25, pageName: 'notes_page')
            ->withQueryString();

        return [
            'list' => $notes->through(fn (CommunitySecurityNote $note) => CommunityInformationPresenter::note($note)),
            'filters' => ['status' => $filters['status'] ?? '', 'category' => $filters['category'] ?? ''],
        ];
    }
}
