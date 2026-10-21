<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Http\Presenters\CommunityInformationPresenter;
use App\Models\ReligiousInstitution;
use App\Models\User;
use App\Support\Exports\Lists\LgaTabExporter;
use App\Support\Exports\Lists\ListColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Religious institutions: the LGA (or community) page tab (Phase 2C list,
 * 11E export). Leaders are persons, so they are not named in the file.
 */
final class ReligiousInstitutionsExporter extends LgaTabExporter
{
    public function key(): string
    {
        return 'religious_institutions';
    }

    public function label(): string
    {
        return 'Religious institutions';
    }

    public function page(): string
    {
        return 'lgas.show#religious_institutions';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', ReligiousInstitution::class);
    }

    protected function tabRules(User $user): array
    {
        return [
            'religion' => ['nullable', 'string', 'size:26'],
            'type' => ['nullable', 'string', Rule::in(array_column(CommunityInformationPresenter::options()['institutionTypes'], 'value'))],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ReligiousInstitution>
     */
    public function query(User $user, array $filters): Builder
    {
        [$lga, $community] = $this->place($user, $filters);

        return ReligiousInstitution::query()
            ->when($lga === null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('religious_institutions.lga_id', $lga?->id)
            ->when($community, fn (Builder $query) => $query->where('religious_institutions.community_id', $community?->id))
            ->withCoordinates()
            ->when($filters['religion'] ?? null, fn (Builder $query, string $religion) => $query->whereHas('religion', fn (Builder $q) => $q->where('ulid', $religion)))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('religious_institutions.type', $type))
            ->orderBy('religious_institutions.name');
    }

    public function with(): array
    {
        return ['religion', 'denomination', 'community', 'lga'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('name', 'Name', fn (ReligiousInstitution $i) => $i->name),
            ListColumn::make('religion', 'Religion', fn (ReligiousInstitution $i) => $i->religion->name),
            ListColumn::make('denomination', 'Denomination', fn (ReligiousInstitution $i) => $i->denomination?->name),
            ListColumn::make('type', 'Type', fn (ReligiousInstitution $i) => $i->type->label()),
            ListColumn::make('community', 'Community', fn (ReligiousInstitution $i) => $i->community->name),
            ListColumn::make('lga', 'LGA', fn (ReligiousInstitution $i) => $i->lga->name),
            ListColumn::make('congregation', 'Estimated congregation', fn (ReligiousInstitution $i) => $i->estimated_congregation),
            ListColumn::make('latitude', 'Latitude', fn (ReligiousInstitution $i) => $i->latitude === null ? null : round((float) $i->latitude, 6)),
            ListColumn::make('longitude', 'Longitude', fn (ReligiousInstitution $i) => $i->longitude === null ? null : round((float) $i->longitude, 6)),
            ListColumn::make('notes', 'Notes', fn (ReligiousInstitution $i) => self::text($i, 'notes')),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'religion', 'denomination', 'type', 'community', 'congregation'];
    }
}
