<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Http\Presenters\CriminalGroupPresenter;
use App\Models\CriminalGroup;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Search\Trigram;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Criminal groups (Phase 3D list, 11E export): the groups only. Members are
 * the separate, sensitive criminal_group_members export.
 */
final class CriminalGroupsExporter extends ListExporter
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'criminal_groups';
    }

    public function label(): string
    {
        return 'Criminal groups';
    }

    public function page(): string
    {
        return 'criminal-groups.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', CriminalGroup::class);
    }

    public function rules(User $user): array
    {
        $options = CriminalGroupPresenter::options();

        return [
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', Rule::in(array_column($options['types'], 'value'))],
            'status' => ['nullable', 'string', Rule::in(array_column($options['statuses'], 'value'))],
            'classification' => ['nullable', 'string', Rule::in(array_column($options['classifications'], 'value'))],
            'lga' => ['nullable', 'string', Rule::in($this->options->lgas($user)->pluck('ulid'))],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CriminalGroup>
     */
    public function query(User $user, array $filters): Builder
    {
        $lga = isset($filters['lga']) ? $this->options->lgas($user)->firstWhere('ulid', $filters['lga']) : null;

        return CriminalGroup::query()
            ->withCount(['members as visible_members_count' => fn (Builder $q) => $q->whereHas('person')])
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('criminal_groups.type', $type))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('criminal_groups.status', $status))
            ->when($filters['classification'] ?? null, fn (Builder $q, string $level) => $q->where('criminal_groups.classification', $level))
            ->when($lga, fn (Builder $q) => $q->where('criminal_groups.lga_id', $lga?->id))
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->search($term), fn (Builder $q) => $q->orderBy('criminal_groups.name'));
    }

    public function around(Closure $callback): mixed
    {
        return Trigram::withWordSimilarity(CriminalGroup::SEARCH_SIMILARITY, $callback);
    }

    public function classificationColumn(): string
    {
        return 'criminal_groups.classification';
    }

    public function with(): array
    {
        return ['state', 'lga'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('name', 'Name', fn (CriminalGroup $g) => $g->name),
            ListColumn::make('other_names', 'Other names', fn (CriminalGroup $g) => implode(', ', $g->other_names)),
            ListColumn::make('type', 'Type', fn (CriminalGroup $g) => $g->type->label()),
            ListColumn::make('status', 'Status', fn (CriminalGroup $g) => $g->status->label()),
            ListColumn::make('estimated_size', 'Estimated size', fn (CriminalGroup $g) => $g->estimated_size),
            ListColumn::make('members', 'Members on record (visible to you)', fn (CriminalGroup $g) => (int) $g->getAttribute('visible_members_count')),
            ListColumn::make('lga', 'LGA', fn (CriminalGroup $g) => $g->lga->name),
            ListColumn::make('state', 'State', fn (CriminalGroup $g) => $g->state->name),
            ListColumn::make('description', 'Description', fn (CriminalGroup $g) => self::text($g, 'description')),
            ListColumn::make('classification', 'Classification', fn (CriminalGroup $g) => $g->classificationLevel()->label()),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'type', 'status', 'members', 'lga', 'state', 'classification'];
    }
}
