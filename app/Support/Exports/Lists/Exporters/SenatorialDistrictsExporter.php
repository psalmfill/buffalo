<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\PoliticalOfficeType;
use App\Models\Lga;
use App\Models\PoliticalOffice;
use App\Models\SenatorialDistrict;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Senatorial districts within reach (Phase 3B list, 11E export). Current
 * senators are public office holders, listed only for users who may view
 * political offices, as on the page.
 */
final class SenatorialDistrictsExporter extends ListExporter
{
    /** @var array<int, string> */
    private array $senators = [];

    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'senatorial_districts';
    }

    public function label(): string
    {
        return 'Senatorial districts';
    }

    public function page(): string
    {
        return 'senatorial-districts.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', Lga::class);
    }

    public function rules(User $user): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<SenatorialDistrict>
     */
    public function query(User $user, array $filters): Builder
    {
        return $this->options->districtQuery($user)
            ->join('states', 'states.id', '=', 'senatorial_districts.state_id')
            ->select('senatorial_districts.*')
            // After select(), which would otherwise drop the count.
            ->withCount(['lgas' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('states.name')
            ->orderBy('senatorial_districts.name');
    }

    public function with(): array
    {
        return ['state'];
    }

    public function prepare(Collection $rows, User $user): void
    {
        $this->senators = $user->can('viewAny', PoliticalOffice::class)
            ? PoliticalOffice::query()->current()->with('person')
                ->where('office', PoliticalOfficeType::Senator->value)
                ->whereIn('senatorial_district_id', $rows->pluck('id')->all())
                ->get()
                ->groupBy('senatorial_district_id')
                ->map(fn (Collection $offices) => $offices->map(fn (PoliticalOffice $o) => $o->person?->name)->filter()->implode(', '))
                ->all()
            : [];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('name', 'Senatorial district', fn (SenatorialDistrict $d) => $d->name),
            ListColumn::make('code', 'Code', fn (SenatorialDistrict $d) => $d->code),
            ListColumn::make('state', 'State', fn (SenatorialDistrict $d) => $d->state->name),
            ListColumn::make('lgas', 'LGAs', fn (SenatorialDistrict $d) => (int) $d->getAttribute('lgas_count')),
            ListColumn::make('senators', 'Current senator', fn (SenatorialDistrict $d) => $this->senators[$d->id] ?? null),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'code', 'state', 'lgas', 'senators'];
    }
}
