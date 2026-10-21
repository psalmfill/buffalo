<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\FormationStatus;
use App\Enums\FormationType;
use App\Models\AgencyFormation;
use App\Models\SecurityAgency;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Agency formations (Phase 4A list, 11E export). Commanders' names, ranks
 * and official phone numbers stay out of bulk files, as in the 7D LGA
 * profile; they remain on each formation's page.
 */
final class FormationsExporter extends ListExporter
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'formations';
    }

    public function label(): string
    {
        return 'Agency formations';
    }

    public function page(): string
    {
        return 'formations.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', AgencyFormation::class);
    }

    public function rules(User $user): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'agency' => ['nullable', 'string', 'size:26'],
            'type' => ['nullable', Rule::enum(FormationType::class)],
            'status' => ['nullable', Rule::enum(FormationStatus::class)],
            'state' => ['nullable', 'string', Rule::in($this->options->states($user)->pluck('ulid'))],
            'zone' => PlaceFilter::zoneRule($user),
            'lga' => ['nullable', 'string', 'size:26'],
            'temporary' => ['nullable', Rule::in(['yes', 'no'])],
        ];
    }

    /**
     * The filtered formations without an order (the page adds its own).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<AgencyFormation>
     */
    public function filtered(User $user, array $filters): Builder
    {
        $state = isset($filters['state']) ? $this->options->states($user)->firstWhere('ulid', $filters['state']) : null;
        $zone = PlaceFilter::zone($filters['zone'] ?? null);
        $lga = isset($filters['lga']) ? $this->options->lgas($user, $state)->firstWhere('ulid', $filters['lga']) : null;
        $agency = isset($filters['agency']) ? SecurityAgency::query()->where('ulid', $filters['agency'])->first() : null;

        return AgencyFormation::query()
            ->when($agency, fn (Builder $q) => $q->where('agency_formations.security_agency_id', $agency?->id))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('agency_formations.formation_type', $type))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('agency_formations.status', $status))
            ->when($state, fn (Builder $q) => $q->where('agency_formations.state_id', $state?->id))
            ->tap(fn (Builder $q) => PlaceFilter::apply($q, 'agency_formations.state_id', $zone))
            ->when($lga, fn (Builder $q) => $q->where('agency_formations.lga_id', $lga?->id))
            ->when($filters['temporary'] ?? null, fn (Builder $q, string $temporary) => $q->where('agency_formations.is_temporary', $temporary === 'yes'))
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->search($term));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AgencyFormation>
     */
    public function query(User $user, array $filters): Builder
    {
        return $this->filtered($user, $filters)
            ->withCoordinates()
            ->when(! isset($filters['q']), fn (Builder $q) => $q->orderBy('agency_formations.name'));
    }

    public function classificationColumn(): string
    {
        return 'agency_formations.classification';
    }

    public function with(): array
    {
        return ['agency', 'parent', 'state', 'lga', 'community'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('name', 'Name', fn (AgencyFormation $f) => $f->name),
            ListColumn::make('agency', 'Agency', fn (AgencyFormation $f) => $f->agency->short_name ?: $f->agency->name),
            ListColumn::make('type', 'Type', fn (AgencyFormation $f) => $f->formation_type->label()),
            ListColumn::make('parent', 'Parent formation', fn (AgencyFormation $f) => $f->parent?->name),
            ListColumn::make('status', 'Status', fn (AgencyFormation $f) => $f->status->label()),
            ListColumn::make('temporary', 'Temporary', fn (AgencyFormation $f) => $f->is_temporary),
            ListColumn::make('active_from', 'Active from', fn (AgencyFormation $f) => self::date($f->active_from)),
            ListColumn::make('active_to', 'Active to', fn (AgencyFormation $f) => self::date($f->active_to)),
            ListColumn::make('state', 'State', fn (AgencyFormation $f) => $f->state->name),
            ListColumn::make('lga', 'LGA', fn (AgencyFormation $f) => $f->lga->name),
            ListColumn::make('community', 'Community', fn (AgencyFormation $f) => $f->community?->name),
            ListColumn::make('address', 'Address', fn (AgencyFormation $f) => $f->address),
            ListColumn::make('latitude', 'Latitude', fn (AgencyFormation $f) => $f->latitude === null ? null : round((float) $f->latitude, 6)),
            ListColumn::make('longitude', 'Longitude', fn (AgencyFormation $f) => $f->longitude === null ? null : round((float) $f->longitude, 6)),
            ListColumn::make('personnel', 'Personnel strength', fn (AgencyFormation $f) => $f->personnel_strength),
            ListColumn::make('notes', 'Notes', fn (AgencyFormation $f) => self::text($f, 'notes')),
            ListColumn::make('classification', 'Classification', fn (AgencyFormation $f) => $f->classificationLevel()->label()),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'agency', 'type', 'status', 'lga', 'state', 'classification'];
    }
}
