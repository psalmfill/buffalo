<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Models\GeopoliticalZone;
use App\Models\State;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\LocalGovernment\StateFacts;
use App\Support\LocalGovernment\StateListFigures;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * States within reach (Phase 11F list and export), with the list's figures
 * (StateListFigures, through the requester's scopes). Not sensitive.
 */
final class StatesExporter extends ListExporter
{
    /** @var array<int, array<string, mixed>> */
    private array $figures = [];

    /** @var array<int, array<string, mixed>> */
    private array $facts = [];

    public function __construct(private readonly JurisdictionOptions $options, private readonly StateListFigures $listFigures) {}

    public function key(): string
    {
        return 'states';
    }

    public function label(): string
    {
        return 'States';
    }

    public function page(): string
    {
        return 'states.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', State::class);
    }

    public function rules(User $user): array
    {
        $states = $this->options->states($user);

        return [
            'zone' => ['nullable', 'string', Rule::in(GeopoliticalZone::query()->whereIn('id', $states->pluck('geopolitical_zone_id')->unique()->all())->pluck('ulid'))],
            'state' => ['nullable', 'string', Rule::in($states->pluck('ulid'))],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<State>
     */
    public function query(User $user, array $filters): Builder
    {
        return $this->options->stateQuery($user)
            ->active()
            ->when($filters['zone'] ?? null, fn (Builder $query, string $zone) => $query->whereHas('geopoliticalZone', fn (Builder $q) => $q->where('ulid', $zone)))
            ->when($filters['state'] ?? null, fn (Builder $query, string $state) => $query->where('states.ulid', $state))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                ->where('states.name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhereRaw('upper(states.code) = upper(?)', [$search])))
            ->orderBy('states.name');
    }

    public function with(): array
    {
        return ['geopoliticalZone'];
    }

    public function prepare(Collection $rows, User $user): void
    {
        $states = $rows->filter(fn ($row) => $row instanceof State);
        $this->figures = $this->listFigures->for($user, $states);
        $this->facts = StateFacts::forStates(array_values($states->map(fn (State $s) => $s->id)->all()))->all();
    }

    public function columns(): array
    {
        return [
            ListColumn::make('name', 'State', fn (State $s) => $s->name),
            ListColumn::make('code', 'Code', fn (State $s) => $s->code),
            ListColumn::make('zone', 'Geopolitical zone', fn (State $s) => $s->geopoliticalZone->name),
            ListColumn::make('capital', 'Capital', fn (State $s) => $s->capital),
            ListColumn::make('lgas', 'LGAs', fn (State $s) => (int) ($this->facts[$s->id]['lgas'] ?? 0)),
            ListColumn::make('director', 'State Director', fn (State $s) => $this->figures[$s->id]['director'] ?? 'Vacant'),
            ListColumn::make('completeness', 'Profile completeness (%)', fn (State $s) => (int) ($this->figures[$s->id]['completeness'] ?? 0)),
            ListColumn::make('last_reviewed', 'Profile last reviewed', fn (State $s) => ($at = $this->figures[$s->id]['last_reviewed_at'] ?? null) instanceof CarbonInterface ? self::at($at) : null),
            ListColumn::make('incidents30', 'Incidents (30 days)', fn (State $s) => $this->figures[$s->id]['incidents30'] ?? null),
            ListColumn::make('immediate_threats', 'Active immediate threats', fn (State $s) => $this->figures[$s->id]['immediateThreats'] ?? null),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'zone', 'capital', 'lgas', 'director', 'completeness', 'last_reviewed', 'incidents30', 'immediate_threats'];
    }
}
