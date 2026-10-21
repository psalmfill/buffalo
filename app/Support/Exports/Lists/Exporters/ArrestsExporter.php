<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\ArrestStatus;
use App\Models\AgencyFormation;
use App\Models\Arrest;
use App\Models\ArrestPerson;
use App\Models\ArrestPersonOffence;
use App\Models\OffenceType;
use App\Models\SecurityAgency;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Arrests (Phase 11C list, 11E export). Arrested persons are counted, never
 * named: names belong to the sensitive person lists.
 */
final class ArrestsExporter extends ListExporter
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'arrests';
    }

    public function label(): string
    {
        return 'Arrests';
    }

    public function page(): string
    {
        return 'arrests.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', Arrest::class);
    }

    public function rules(User $user): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'agency' => ['nullable', 'string', 'size:26'],
            'formation' => ['nullable', 'string', 'size:26'],
            'offence' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::enum(ArrestStatus::class)],
            'state' => ['nullable', 'string', Rule::in($this->options->states($user)->pluck('ulid'))],
            'zone' => PlaceFilter::zoneRule($user),
            'lga' => ['nullable', 'string', 'size:26'],
            'linked' => ['nullable', Rule::in(['yes', 'no'])],
            'poi' => ['nullable', Rule::in(['yes', 'no'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Arrest>
     */
    public function query(User $user, array $filters): Builder
    {
        $state = isset($filters['state']) ? $this->options->states($user)->firstWhere('ulid', $filters['state']) : null;
        $zone = PlaceFilter::zone($filters['zone'] ?? null);
        $lga = isset($filters['lga']) ? $this->options->lgas($user, $state)->firstWhere('ulid', $filters['lga']) : null;
        $tz = (string) config('app.timezone');
        $poiPersons = fn ($q) => $q->select('arrest_persons.arrest_id')->from('arrest_persons')
            ->join('persons_of_interest', 'persons_of_interest.person_id', '=', 'arrest_persons.person_id')
            ->whereNull('arrest_persons.deleted_at')->whereNull('persons_of_interest.deleted_at');

        return Arrest::query()
            ->when($filters['from'] ?? null, fn (Builder $q, string $v) => $q->where('arrests.arrested_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $v, $tz)?->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $v) => $q->where('arrests.arrested_at', '<', CarbonImmutable::createFromFormat('Y-m-d', $v, $tz)?->addDay()->startOfDay()))
            ->when($filters['agency'] ?? null, fn (Builder $q, string $v) => $q->whereIn('arrests.security_agency_id', SecurityAgency::query()->where('ulid', $v)->select('id')))
            ->when($filters['formation'] ?? null, fn (Builder $q, string $v) => $q->whereIn('arrests.agency_formation_id', AgencyFormation::query()->where('ulid', $v)->select('agency_formations.id')))
            ->when($filters['offence'] ?? null, fn (Builder $q, string $v) => $q->whereHas('persons.offences', fn (Builder $o) => $o->whereIn('offence_type_id', OffenceType::query()->where('ulid', $v)->select('id'))))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('arrests.status', $v))
            ->when($state, fn (Builder $q) => $q->where('arrests.state_id', $state?->id))
            ->tap(fn (Builder $q) => PlaceFilter::apply($q, 'arrests.state_id', $zone))
            ->when($lga, fn (Builder $q) => $q->where('arrests.lga_id', $lga?->id))
            ->when(($filters['linked'] ?? null) === 'yes', fn (Builder $q) => $q->whereNotNull('arrests.incident_id'))
            ->when(($filters['linked'] ?? null) === 'no', fn (Builder $q) => $q->whereNull('arrests.incident_id'))
            ->when(($filters['poi'] ?? null) === 'yes', fn (Builder $q) => $q->whereIn('arrests.id', $poiPersons))
            ->when(($filters['poi'] ?? null) === 'no', fn (Builder $q) => $q->whereNotIn('arrests.id', $poiPersons))
            ->orderByDesc('arrests.arrested_at')->orderByDesc('arrests.id');
    }

    public function classificationColumn(): string
    {
        return 'arrests.classification';
    }

    public function with(): array
    {
        return ['agency', 'formation', 'state', 'lga', 'community', 'incident', 'persons.offences.offenceType'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('reference', 'Reference', fn (Arrest $a) => $a->reference_no),
            ListColumn::make('arrested_at', 'Arrested at', fn (Arrest $a) => self::at($a->arrested_at)),
            ListColumn::make('precision', 'Time precision', fn (Arrest $a) => $a->arrested_at_precision->label()),
            ListColumn::make('agency', 'Agency', fn (Arrest $a) => $a->agency->short_name ?: $a->agency->name),
            ListColumn::make('formation', 'Formation', fn (Arrest $a) => $a->formation?->name),
            ListColumn::make('persons', 'Persons arrested', fn (Arrest $a) => $a->persons->count()),
            ListColumn::make('offences', 'Offences', fn (Arrest $a) => $a->persons->flatMap(fn (ArrestPerson $p) => $p->offences)
                ->map(fn (ArrestPersonOffence $o) => $o->offenceType->name)->unique()->sort()->implode(', ')),
            ListColumn::make('status', 'Status', fn (Arrest $a) => $a->status->label()),
            ListColumn::make('state', 'State', fn (Arrest $a) => $a->state->name),
            ListColumn::make('lga', 'LGA', fn (Arrest $a) => $a->lga->name),
            ListColumn::make('community', 'Community', fn (Arrest $a) => $a->community?->name),
            ListColumn::make('incident', 'Incident', fn (Arrest $a) => $a->incident?->reference_no),
            ListColumn::make('notes', 'Notes', fn (Arrest $a) => self::text($a, 'notes')),
            ListColumn::make('classification', 'Classification', fn (Arrest $a) => $a->classificationLevel()->label()),
        ];
    }

    public function defaultColumns(): array
    {
        return ['reference', 'arrested_at', 'agency', 'persons', 'offences', 'status', 'lga', 'incident'];
    }
}
