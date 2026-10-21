<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Models\User;
use App\Models\VulnerablePopulationStat;
use App\Support\Exports\Lists\LgaTabExporter;
use App\Support\Exports\Lists\ListColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Vulnerable population figures: the LGA (or community) page tab (Phase 2C
 * list, 11E export). Aggregate counts only, as recorded: the latest figure
 * of each series (LGA-wide rows are the authoritative LGA figures;
 * community rows are breakdowns and are never summed).
 */
final class VulnerableFiguresExporter extends LgaTabExporter
{
    public function key(): string
    {
        return 'vulnerable_population';
    }

    public function label(): string
    {
        return 'Vulnerable population figures';
    }

    public function page(): string
    {
        return 'lgas.show#vulnerable_population';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', VulnerablePopulationStat::class);
    }

    protected function tabRules(User $user): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<VulnerablePopulationStat>
     */
    public function query(User $user, array $filters): Builder
    {
        [$lga, $community] = $this->place($user, $filters);

        return VulnerablePopulationStat::query()->latestPerSeries()
            ->when($lga === null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('vulnerable_population_stats.lga_id', $lga?->id)
            ->when($community, fn (Builder $query) => $query->where('vulnerable_population_stats.community_id', $community?->id));
    }

    public function with(): array
    {
        return ['lga', 'community'];
    }

    public function columns(): array
    {
        $total = fn (VulnerablePopulationStat $f) => $f->adult_male + $f->adult_female + $f->child_male + $f->child_female;

        return [
            ListColumn::make('lga', 'LGA', fn (VulnerablePopulationStat $f) => $f->lga->name),
            ListColumn::make('community', 'Community', fn (VulnerablePopulationStat $f) => $f->community->name ?? 'LGA-wide'),
            ListColumn::make('category', 'Category', fn (VulnerablePopulationStat $f) => $f->category->label()),
            ListColumn::make('adult_male', 'Adult male', fn (VulnerablePopulationStat $f) => $f->adult_male),
            ListColumn::make('adult_female', 'Adult female', fn (VulnerablePopulationStat $f) => $f->adult_female),
            ListColumn::make('child_male', 'Child male', fn (VulnerablePopulationStat $f) => $f->child_male),
            ListColumn::make('child_female', 'Child female', fn (VulnerablePopulationStat $f) => $f->child_female),
            ListColumn::make('total', 'Total', $total),
            ListColumn::make('as_of', 'As of', fn (VulnerablePopulationStat $f) => $f->as_of_date->format('Y-m-d')),
            ListColumn::make('source', 'Source', fn (VulnerablePopulationStat $f) => $f->source),
        ];
    }

    public function defaultColumns(): array
    {
        return ['community', 'category', 'adult_male', 'adult_female', 'child_male', 'child_female', 'total', 'as_of', 'source'];
    }
}
