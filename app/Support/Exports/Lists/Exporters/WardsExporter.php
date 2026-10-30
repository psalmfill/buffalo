<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Models\Lga;
use App\Models\User;
use App\Models\Ward;
use App\Support\Exports\Lists\LgaTabExporter;
use App\Support\Exports\Lists\ListColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Wards: the LGA page tab (CRUD coverage step). Wards are reference data;
 * the community count goes through the communities' own scope. Deactivated
 * wards are listed too (after the active ones), because records may still
 * use them.
 */
final class WardsExporter extends LgaTabExporter
{
    public function key(): string
    {
        return 'wards';
    }

    public function label(): string
    {
        return 'Wards';
    }

    public function page(): string
    {
        return 'lgas.show#wards';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', Lga::class);
    }

    protected function tabRules(User $user): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Ward>
     */
    public function query(User $user, array $filters): Builder
    {
        [$lga] = $this->place($user, $filters);

        return Ward::query()
            ->when($lga === null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('wards.lga_id', $lga?->id)
            ->withCount('communities')
            ->orderByDesc('wards.is_active')
            ->orderBy('wards.name');
    }

    public function columns(): array
    {
        return [
            ListColumn::make('name', 'Ward', fn (Ward $w) => $w->name),
            ListColumn::make('code', 'Code', fn (Ward $w) => $w->code),
            ListColumn::make('status', 'Status', fn (Ward $w) => $w->is_active ? 'Active' : 'Deactivated'),
            ListColumn::make('communities', 'Communities', fn (Ward $w) => (int) $w->getAttribute('communities_count')),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'code', 'status', 'communities'];
    }
}
