<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Models\Lga;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\LocalGovernment\ProfileCompleteness;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * LGAs within reach (Phase 2B list, 11E export), with profile completeness
 * and community counts as on the page.
 */
final class LgasExporter extends ListExporter
{
    /** @var array<int, array<string, mixed>> */
    private array $stats = [];

    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'lgas';
    }

    public function label(): string
    {
        return 'LGAs';
    }

    public function page(): string
    {
        return 'lgas.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', Lga::class);
    }

    public function rules(User $user): array
    {
        return [
            'state' => ['nullable', 'string', Rule::in($this->options->states($user)->pluck('ulid'))],
            'district' => ['nullable', 'string', 'size:26'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Lga>
     */
    public function query(User $user, array $filters): Builder
    {
        $state = isset($filters['state']) ? $this->options->states($user)->firstWhere('ulid', $filters['state']) : null;

        return $this->options->lgaQuery($user)
            ->active()
            ->when($state, fn (Builder $query) => $query->where('lgas.state_id', $state?->id))
            ->when($filters['district'] ?? null, fn (Builder $query, string $district) => $query->whereHas('senatorialDistrict', fn (Builder $q) => $q->where('ulid', $district)))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('lgas.name', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->join('states', 'states.id', '=', 'lgas.state_id')
            ->orderBy('states.name')
            ->orderBy('lgas.name')
            ->select('lgas.*');
    }

    public function with(): array
    {
        return ['state', 'senatorialDistrict'];
    }

    public function prepare(Collection $rows, User $user): void
    {
        $this->stats = ProfileCompleteness::forLgas($rows->filter(fn ($row) => $row instanceof Lga)->all())->all();
    }

    public function columns(): array
    {
        return [
            ListColumn::make('name', 'LGA', fn (Lga $l) => $l->name),
            ListColumn::make('code', 'Code', fn (Lga $l) => $l->code),
            ListColumn::make('state', 'State', fn (Lga $l) => $l->state->name),
            ListColumn::make('district', 'Senatorial district', fn (Lga $l) => $l->senatorialDistrict?->name),
            ListColumn::make('headquarters', 'Headquarters', fn (Lga $l) => $l->headquarters),
            ListColumn::make('communities', 'Communities', fn (Lga $l) => (int) ($this->stats[$l->id]['communities'] ?? 0)),
            ListColumn::make('completeness', 'Profile completeness (%)', fn (Lga $l) => (int) ($this->stats[$l->id]['completeness'] ?? 0)),
            ListColumn::make('last_reviewed', 'Profile last reviewed', fn (Lga $l) => ($at = $this->stats[$l->id]['last_reviewed_at'] ?? null) instanceof CarbonInterface ? self::at($at) : null),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'state', 'district', 'headquarters', 'communities', 'completeness', 'last_reviewed'];
    }
}
