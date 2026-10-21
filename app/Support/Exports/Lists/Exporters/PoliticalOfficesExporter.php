<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\PoliticalOfficeType;
use App\Models\Lga;
use App\Models\PoliticalOffice;
use App\Models\User;
use App\Support\Exports\Lists\LgaTabExporter;
use App\Support\Exports\Lists\ListColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Current LGA office holders: the LGA page's Leadership tab (Phase 3B list,
 * 11E export): chairman, vice chairman and councillors. Current public
 * office holders are the only names, as in the LGA profile (CLAUDE.md);
 * traditional rulers and religious leaders are persons and stay out.
 */
final class PoliticalOfficesExporter extends LgaTabExporter
{
    public const OFFICES = [PoliticalOfficeType::LgaChairman, PoliticalOfficeType::LgaViceChairman, PoliticalOfficeType::Councillor];

    public function key(): string
    {
        return 'political_offices';
    }

    public function label(): string
    {
        return 'LGA office holders';
    }

    public function page(): string
    {
        return 'lgas.show#leadership';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', PoliticalOffice::class);
    }

    protected function tabRules(User $user): array
    {
        return [];
    }

    /**
     * The LGA's current chairman, vice chairman and councillors (shared
     * with the Leadership tab).
     *
     * @return Builder<PoliticalOffice>
     */
    public static function currentForLga(?Lga $lga): Builder
    {
        return PoliticalOffice::query()
            ->current()
            ->when($lga === null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('political_offices.lga_id', $lga?->id)
            ->whereIn('political_offices.office', array_map(fn (PoliticalOfficeType $t) => $t->value, self::OFFICES))
            ->orderBy('political_offices.started_on')
            ->orderBy('political_offices.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<PoliticalOffice>
     */
    public function query(User $user, array $filters): Builder
    {
        return self::currentForLga($this->place($user, $filters)[0]);
    }

    public function with(): array
    {
        return ['person', 'lga', 'ward'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('office', 'Office', fn (PoliticalOffice $o) => $o->office->label()),
            ListColumn::make('title', 'Title', fn (PoliticalOffice $o) => $o->office_title),
            ListColumn::make('name', 'Office holder', fn (PoliticalOffice $o) => $o->person->name ?? 'Not visible to you'),
            ListColumn::make('party', 'Party', fn (PoliticalOffice $o) => $o->party),
            ListColumn::make('ward', 'Ward', fn (PoliticalOffice $o) => $o->ward?->name),
            ListColumn::make('lga', 'LGA', fn (PoliticalOffice $o) => $o->lga?->name),
            ListColumn::make('started_on', 'In office since', fn (PoliticalOffice $o) => $o->started_on->format('Y-m-d')),
        ];
    }

    public function defaultColumns(): array
    {
        return ['office', 'name', 'party', 'ward', 'started_on'];
    }
}
