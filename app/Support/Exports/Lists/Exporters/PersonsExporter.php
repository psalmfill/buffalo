<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\Classification;
use App\Enums\Gender;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\PersonRole;
use App\Models\User;
use App\Support\Exports\AgeBand;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use App\Support\Persons\PersonSearch;
use App\Support\Persons\SearchTerm;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Persons (Phase 3A search page, 11E export). SENSITIVE: exports.sensitive,
 * step-up and a reason every time, at most 1,000 rows.
 *
 * The rows are the page's own results for the same search (never the
 * cross-jurisdiction hits): by name, or by the filters; with neither, the
 * most recently updated (the page's "recent" list). A search by number (NIN
 * or phone) is never exported, so no NIN or phone reaches a run's stored
 * parameters. Columns never include encrypted fields (NIN, phone, address,
 * date of birth) or photos: the age is a band.
 */
final class PersonsExporter extends ListExporter
{
    public const RECENT_LIMIT = 20;

    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'persons';
    }

    public function label(): string
    {
        return 'Persons';
    }

    public function page(): string
    {
        return 'persons.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', Person::class);
    }

    public function sensitive(): bool
    {
        return true;
    }

    public function rules(User $user): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::exists('person_role_types', 'code')],
            'state' => ['nullable', 'string', 'size:26'],
            'zone' => ['nullable', 'string', 'size:26'],
            'lga' => ['nullable', 'string', 'size:26'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'classification' => ['nullable', Rule::enum(Classification::class)],
        ];
    }

    public function filters(array $input, User $user): array
    {
        $filters = parent::filters($input, $user);

        if (SearchTerm::parse((string) ($filters['q'] ?? ''))->isNumber()) {
            throw ValidationException::withMessages(['filters' => 'A search by number cannot be exported. Search by name or use the filters.']);
        }

        return $filters;
    }

    /**
     * The page's filtered persons, without a search or an order.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Person>
     */
    public function filtered(User $user, array $filters): Builder
    {
        $states = $this->options->states($user);
        $state = isset($filters['state']) ? $states->firstWhere('ulid', $filters['state']) : null;
        $lga = isset($filters['lga']) ? $this->options->lgas($user, $state)->firstWhere('ulid', $filters['lga']) : null;
        $zone = PlaceFilter::options($user) !== [] ? PlaceFilter::zone((string) ($filters['zone'] ?? '')) : null;

        return Person::query()
            ->when($state, fn (Builder $q) => $q->where('persons.state_id', $state?->id))
            ->tap(fn (Builder $q) => PlaceFilter::apply($q, 'persons.state_id', $zone))
            ->when($lga, fn (Builder $q) => $q->where('persons.lga_id', $lga?->id))
            ->when($filters['role'] ?? null, fn (Builder $q, string $role) => $q->whereHas('roles', fn (Builder $r) => $r->where('is_active', true)->whereHas('type', fn (Builder $t) => $t->where('code', $role))))
            ->when($filters['gender'] ?? null, fn (Builder $q, string $gender) => $q->where('persons.gender', $gender))
            ->when($filters['classification'] ?? null, fn (Builder $q, string $level) => $q->where('persons.classification', $level));
    }

    /**
     * The page's mode: recent (no search, no filters), filtered, number or name.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function mode(array $filters): string
    {
        $term = SearchTerm::parse((string) ($filters['q'] ?? ''));
        $filtered = array_intersect_key($filters, array_flip(['state', 'zone', 'lga', 'role', 'gender', 'classification'])) !== [];

        return match (true) {
            $term->isEmpty() && ! $filtered => 'recent',
            $term->isEmpty() => 'filtered',
            $term->isNumber() => 'number',
            default => 'name',
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Person>
     */
    public function query(User $user, array $filters): Builder
    {
        $query = $this->filtered($user, $filters);
        $term = SearchTerm::parse((string) ($filters['q'] ?? ''));

        return match (self::mode($filters)) {
            // As a subquery: chunked reading replaces any limit on the query itself.
            'recent' => $query->whereIn('persons.id', (clone $query)->select('persons.id')->orderByDesc('persons.updated_at')->orderByDesc('persons.id')->limit(self::RECENT_LIMIT))
                ->orderByDesc('persons.updated_at')->orderByDesc('persons.id'),
            'filtered' => $query->orderBy('persons.last_name')->orderBy('persons.first_name')->orderBy('persons.id'),
            'number' => $query->whereRaw('1 = 0'),
            default => PersonSearch::byName($query, $term->raw),
        };
    }

    /** Name searches use the page's trigram thresholds. */
    public function around(Closure $callback): mixed
    {
        return PersonSearch::withThresholds(PersonSearch::SIMILARITY, PersonSearch::WORD_SIMILARITY, $callback);
    }

    public function classificationColumn(): string
    {
        return 'persons.classification';
    }

    public function with(): array
    {
        return ['state', 'lga', 'aliases', 'roles.type'];
    }

    /**
     * @return list<ListColumn>
     */
    public static function personColumns(Closure $person): array
    {
        return [
            ListColumn::make('name', 'Name', fn ($row) => $person($row)->name),
            ListColumn::make('other_names', 'Other names', fn ($row) => $person($row)->other_names),
            ListColumn::make('aliases', 'Aliases', fn ($row) => $person($row)->aliases->map(fn (PersonAlias $a) => $a->alias)->implode(', ')),
            ListColumn::make('gender', 'Gender', fn ($row) => $person($row)->gender->label()),
            ListColumn::make('age_band', 'Age band', fn ($row) => AgeBand::for($person($row)->date_of_birth, $person($row)->approximate_age)),
            ListColumn::make('occupation', 'Occupation', fn ($row) => $person($row)->occupation),
            ListColumn::make('roles', 'Roles', fn ($row) => $person($row)->roles->where('is_active', true)->map(fn (PersonRole $r) => $r->type->name)->unique()->implode(', ')),
            ListColumn::make('lga', 'LGA', fn ($row) => $person($row)->lga->name),
            ListColumn::make('state', 'State', fn ($row) => $person($row)->state->name),
            ListColumn::make('classification', 'Classification', fn ($row) => $person($row)->classificationLevel()->label()),
            ListColumn::make('updated_at', 'Last updated', fn ($row) => self::at($person($row)->updated_at)),
        ];
    }

    public function columns(): array
    {
        return self::personColumns(fn (Person $p) => $p);
    }

    public function defaultColumns(): array
    {
        return ['name', 'other_names', 'aliases', 'gender', 'age_band', 'occupation', 'roles', 'lga', 'state', 'classification', 'updated_at'];
    }
}
