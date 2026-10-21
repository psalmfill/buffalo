<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Http\Presenters\PoiPresenter;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Persons of interest (Phase 3D list, 11E export). SENSITIVE: the person
 * columns of the persons export (no encrypted fields, no photos, an age
 * band) plus risk level, status, offence categories, last sighted and
 * review due. Free-text offences, modus operandi and last known locations
 * stay out of bulk files.
 */
final class PersonsOfInterestExporter extends ListExporter
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'persons_of_interest';
    }

    public function label(): string
    {
        return 'Persons of interest';
    }

    public function page(): string
    {
        return 'persons-of-interest.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', PersonOfInterest::class);
    }

    public function sensitive(): bool
    {
        return true;
    }

    public function rules(User $user): array
    {
        $options = PoiPresenter::options();

        return [
            'risk' => ['nullable', 'string', Rule::in(array_column($options['riskLevels'], 'value'))],
            'status' => ['nullable', 'string', Rule::in(array_column($options['statuses'], 'value'))],
            'offence' => ['nullable', 'string', 'max:50'],
            // State and zone (Phase 11A.2, the state lens).
            'state' => ['nullable', 'string', Rule::in($this->options->states($user)->pluck('ulid'))],
            'zone' => PlaceFilter::zoneRule($user),
            'lga' => ['nullable', 'string', 'size:26'],
            'overdue' => ['nullable', 'in:1'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<PersonOfInterest>
     */
    public function query(User $user, array $filters): Builder
    {
        $state = isset($filters['state']) ? $this->options->states($user)->firstWhere('ulid', $filters['state']) : null;
        $zone = PlaceFilter::zone($filters['zone'] ?? null);
        $lga = isset($filters['lga']) ? $this->options->lgas($user, $state)->firstWhere('ulid', $filters['lga']) : null;

        return PersonOfInterest::query()
            ->when($filters['risk'] ?? null, fn (Builder $q, string $risk) => $q->where('persons_of_interest.risk_level', $risk))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('persons_of_interest.status', $status))
            ->when($filters['offence'] ?? null, fn (Builder $q, string $code) => $q->whereJsonContains('persons_of_interest.offence_categories', $code))
            ->when($state, fn (Builder $q) => $q->where('persons_of_interest.state_id', $state?->id))
            ->tap(fn (Builder $q) => PlaceFilter::apply($q, 'persons_of_interest.state_id', $zone))
            ->when($lga, fn (Builder $q) => $q->where('persons_of_interest.lga_id', $lga?->id))
            ->when(isset($filters['overdue']), fn (Builder $q) => $q->reviewOverdue())
            ->byPriority();
    }

    /** A POI has its person's classification. */
    public function levels(User $user, array $filters): iterable
    {
        return Person::query()
            ->whereIn('persons.id', $this->query($user, $filters)->toBase()->cloneWithout(['orders', 'columns'])->cloneWithoutBindings(['select', 'order'])->select('persons_of_interest.person_id'))
            ->distinct()
            ->pluck('persons.classification');
    }

    public function with(): array
    {
        return ['person.state', 'person.lga', 'person.aliases', 'person.roles.type'];
    }

    public function columns(): array
    {
        return [
            ...PersonsExporter::personColumns(fn (PersonOfInterest $poi) => $poi->person),
            ListColumn::make('risk', 'Risk level', fn (PersonOfInterest $poi) => $poi->risk_level->label()),
            ListColumn::make('status', 'Status', fn (PersonOfInterest $poi) => $poi->status->label()),
            ListColumn::make('offences', 'Offence categories', fn (PersonOfInterest $poi) => collect(PoiPresenter::offences($poi->offence_categories))->pluck('label')->implode(', ')),
            ListColumn::make('last_sighted', 'Last sighted', fn (PersonOfInterest $poi) => self::date($poi->last_sighted_at)),
            ListColumn::make('review_due', 'Review due', fn (PersonOfInterest $poi) => self::date($poi->review_due_at)),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'other_names', 'aliases', 'gender', 'age_band', 'occupation', 'roles', 'lga', 'state', 'classification', 'updated_at', 'risk', 'status', 'offences', 'last_sighted', 'review_due'];
    }
}
