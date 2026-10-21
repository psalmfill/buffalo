<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Http\Presenters\CommunityPresenter;
use App\Models\Community;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Search\Trigram;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Communities (Phase 2B list, 11E export). The name search uses the
 * trigram threshold of the page.
 */
final class CommunitiesExporter extends ListExporter
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'communities';
    }

    public function label(): string
    {
        return 'Communities';
    }

    public function page(): string
    {
        return 'communities.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', Community::class);
    }

    public function rules(User $user): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', Rule::in($this->options->states($user)->pluck('ulid'))],
            'lga' => ['nullable', 'string', 'size:26'],
            'type' => ['nullable', 'string', Rule::in(array_column(CommunityPresenter::typeOptions(), 'value'))],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Community>
     */
    public function query(User $user, array $filters): Builder
    {
        $state = isset($filters['state']) ? $this->options->states($user)->firstWhere('ulid', $filters['state']) : null;

        return Community::query()
            ->withCoordinates()
            ->when($state, fn (Builder $query) => $query->where('communities.state_id', $state?->id))
            ->when($filters['lga'] ?? null, fn (Builder $query, string $lga) => $query->whereHas('lga', fn (Builder $q) => $q->where('ulid', $lga)))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('communities.type', $type))
            ->when($filters['q'] ?? null, fn (Builder $query, string $q) => $query->search($q), fn (Builder $query) => $query->orderBy('communities.name'));
    }

    /** The name search needs the page's trigram threshold, for counting and reading alike. */
    public function around(Closure $callback): mixed
    {
        return Trigram::withWordSimilarity(Community::SEARCH_SIMILARITY, $callback);
    }

    public function with(): array
    {
        return ['state', 'lga', 'ward'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('name', 'Name', fn (Community $c) => $c->name),
            ListColumn::make('type', 'Type', fn (Community $c) => $c->type->label()),
            ListColumn::make('state', 'State', fn (Community $c) => $c->state->name),
            ListColumn::make('lga', 'LGA', fn (Community $c) => $c->lga->name),
            ListColumn::make('ward', 'Ward', fn (Community $c) => $c->ward?->name),
            ListColumn::make('latitude', 'Latitude', fn (Community $c) => $c->latitude === null ? null : round((float) $c->latitude, 6)),
            ListColumn::make('longitude', 'Longitude', fn (Community $c) => $c->longitude === null ? null : round((float) $c->longitude, 6)),
            ListColumn::make('other_names', 'Other names', fn (Community $c) => implode(', ', $c->other_names)),
            ListColumn::make('population', 'Population (estimate)', fn (Community $c) => $c->population_estimate),
            ListColumn::make('notes', 'Notes', fn (Community $c) => self::text($c, 'notes')),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'other_names', 'type', 'lga', 'state', 'ward', 'population'];
    }
}
