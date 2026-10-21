<?php

namespace App\Support\Exports\Lists;

use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * A list page's exporter (Phase 11E). The page and its export share the
 * same filter rules and the same scoped query (filters() and query()), so
 * a file always holds exactly the rows the requester's page lists for the
 * same filters, through the requester's own scopes at generation time.
 *
 * Exports never cover "everything regardless of filters": the page's
 * current filters are always applied.
 *
 * Sensitive lists (persons, persons of interest, criminal group members,
 * organization contacts) need exports.sensitive, step-up and a reason for
 * every export, and are capped at buffalo.exports.sensitive_max_rows; their
 * columns never include encrypted fields or photos.
 */
abstract class ListExporter
{
    /** Registry key, e.g. "arrests". */
    abstract public function key(): string;

    /** Heading of the file, e.g. "Arrests". */
    abstract public function label(): string;

    /**
     * The page it exports: an index route name ("arrests.index"), or a
     * page tab ("lgas.show#organizations") for lists shown as a tab.
     */
    abstract public function page(): string;

    /** The list's own view permission or policy (besides exports.lists). */
    abstract public function canView(User $user): bool;

    /**
     * Validation rules of the page's filters.
     *
     * @return array<string, mixed>
     */
    abstract public function rules(User $user): array;

    /**
     * The page's rows for these (validated) filters, through the scopes,
     * in the page's order.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<covariant Model>
     */
    abstract public function query(User $user, array $filters): Builder;

    /**
     * Every column that may be exported, in order.
     *
     * @return list<ListColumn>
     */
    abstract public function columns(): array;

    /**
     * The columns the page shows (exported unless the user chooses others).
     *
     * @return list<string>
     */
    abstract public function defaultColumns(): array;

    public function sensitive(): bool
    {
        return false;
    }

    /** Only super admins may export it (the users list), and they nothing else. */
    public function forSuperAdmin(): bool
    {
        return false;
    }

    /** Step-up for every export, whatever its size (the users list). */
    public function alwaysStepUp(): bool
    {
        return $this->sensitive();
    }

    public function maxRows(): int
    {
        return $this->sensitive()
            ? (int) config('buffalo.exports.sensitive_max_rows')
            : (int) config('buffalo.reports.max_rows');
    }

    /** The column holding each row's classification, or null (restricted). */
    public function classificationColumn(): ?string
    {
        return null;
    }

    /**
     * The classifications among the export's rows (the file's level is the
     * highest): by default the distinct values of classificationColumn().
     *
     * @param  array<string, mixed>  $filters
     * @return iterable<mixed>
     */
    public function levels(User $user, array $filters): iterable
    {
        $column = $this->classificationColumn();

        return $column === null ? [] : $this->query($user, $filters)->toBase()->cloneWithout(['orders', 'columns'])->cloneWithoutBindings(['select', 'order'])->distinct()->pluck($column);
    }

    /**
     * Relations loaded with each chunk.
     *
     * @return list<string>
     */
    public function with(): array
    {
        return [];
    }

    /**
     * Batch lookups for a chunk of rows before they are written (counts,
     * names), so columns never query per row.
     *
     * @param  Collection<int, Model>  $rows
     */
    public function prepare(Collection $rows, User $user): void {}

    /**
     * Runs a query of this list (counting or reading). Lists whose search
     * needs per-query settings (trigram thresholds) wrap it here.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function around(Closure $callback): mixed
    {
        return $callback();
    }

    /**
     * Validated, normalised filters (unknown keys dropped).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function filters(array $input, User $user): array
    {
        $rules = $this->rules($user);
        $values = Validator::make(array_intersect_key($input, $rules), $rules)->validate();

        return array_filter($values, fn (mixed $v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * @return array<string, ListColumn>
     */
    public function columnMap(): array
    {
        $map = [];
        foreach ($this->columns() as $column) {
            $map[$column->key] = $column;
        }

        return $map;
    }

    /**
     * The chosen columns in list order, or the defaults.
     *
     * @param  list<string>|null  $keys
     * @return list<ListColumn>
     */
    public function selected(?array $keys): array
    {
        $keys = $keys === null || $keys === [] ? $this->defaultColumns() : $keys;

        return array_values(array_filter($this->columns(), fn (ListColumn $c) => in_array($c->key, $keys, true)));
    }

    /**
     * The number of rows the export would hold.
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(User $user, array $filters): int
    {
        return $this->around(fn (): int => $this->countRows($user, $filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function countRows(User $user, array $filters): int
    {
        $query = $this->query($user, $filters);
        $base = $query->toBase();

        // Grouped or distinct queries are counted as a subquery.
        if ($base->groups !== null || $base->distinct !== false) {
            return (int) $base->newQuery()->fromSub($base->cloneWithout(['orders'])->cloneWithoutBindings(['order']), 'list_rows')->count();
        }

        return (clone $query)->toBase()->cloneWithout(['orders', 'columns'])->cloneWithoutBindings(['select', 'order'])->count();
    }

    /** A date-time as shown in exports (Africa/Lagos). */
    public static function at(?CarbonInterface $at): ?string
    {
        return $at?->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i');
    }

    /** The plain text copy of a rich text field (Phase 11B `<field>_text`). */
    public static function text(Model $row, string $field): ?string
    {
        $text = $row->getAttribute("{$field}_text");

        return is_string($text) && $text !== '' ? $text : null;
    }

    public static function date(?CarbonInterface $at): ?string
    {
        return $at?->format('Y-m-d');
    }
}
