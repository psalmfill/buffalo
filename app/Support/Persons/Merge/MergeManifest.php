<?php

namespace App\Support\Persons\Merge;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Records every row a merge changes, with the prior values needed to
 * reverse it (Phase 10A.2), while applying the change.
 *
 * Entries (in order):
 * - `update`: columns changed; `prior` and `after` hold their values (a
 *   repointed row has its person column in both);
 * - `drop`: a row soft-deleted by the merge (duplicate combined into the
 *   survivor's row, or the association between the two records); `prior`
 *   holds deleted_at (null) and any other changed column;
 * - `created`: a row the merge inserted (an alias from a losing name);
 * - `revoked` / `cancelled` / `executed`: grants revoked, access or merge
 *   requests cancelled, the executed merge request: never restored.
 *
 * Values are read and written through the query builder exactly as stored
 * (jsonb as JSON text, geography as hex EWKB, timestamps as text), so the
 * undo writes back byte-identical values. Encrypted person fields never
 * appear here: the survivor's own fields go to survivor_prior_values.
 */
final class MergeManifest
{
    /** Tables whose rows a merge may change, and nothing else. */
    public const TABLES = [
        'arrest_person_offences', 'arrest_persons',
        'communities', 'criminal_group_members', 'incident_persons', 'media', 'organization_contacts',
        'person_aliases', 'person_associations', 'person_merge_requests', 'person_roles', 'persons',
        'persons_of_interest', 'political_offices', 'record_access_grants', 'record_access_requests',
        'religious_institutions',
    ];

    /** @var list<array{t: string, id: int, op: string, prior?: array<string, mixed>, after?: array<string, mixed>}> */
    private array $entries = [];

    /**
     * Applies $after to the row and records its prior values.
     *
     * @param  array<string, mixed>  $after
     */
    public function update(string $table, int $id, array $after, string $op = 'update'): void
    {
        self::guard($table);

        $row = DB::table($table)->where('id', $id)->first(array_keys($after));

        if ($row === null) {
            throw new LogicException("{$table} row {$id} does not exist.");
        }

        $prior = [];
        foreach (array_keys($after) as $column) {
            $prior[$column] = $row->{$column};
        }

        DB::table($table)->where('id', $id)->update($after);

        // Read back what was stored (normalised by PostgreSQL), so the
        // undo compares like with like.
        $stored = (array) DB::table($table)->where('id', $id)->first(array_keys($after));
        $this->entries[] = ['t' => $table, 'id' => $id, 'op' => $op, 'prior' => $prior, 'after' => $stored];
    }

    /**
     * Soft-deletes a row (and changes other columns with it).
     *
     * @param  array<string, mixed>  $also
     */
    public function drop(string $table, int $id, array $also = []): void
    {
        $this->update($table, $id, ['deleted_at' => now(), ...$also], 'drop');
    }

    public function created(string $table, int $id): void
    {
        self::guard($table);
        $this->entries[] = ['t' => $table, 'id' => $id, 'op' => 'created'];
    }

    /**
     * A change that is never undone (revoked grant, cancelled request).
     *
     * @param  array<string, mixed>  $after
     */
    public function final(string $table, int $id, string $op, array $after): void
    {
        $this->update($table, $id, $after, $op);
    }

    /**
     * @return list<array{t: string, id: int, op: string, prior?: array<string, mixed>, after?: array<string, mixed>}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * IDs per table and operation, for tests and the handover.
     *
     * @return array<string, list<int>>
     */
    public function idsFor(string $table, ?string $op = null): array
    {
        return [$table => array_values(array_map(
            fn (array $entry): int => $entry['id'],
            array_filter($this->entries, fn (array $entry): bool => $entry['t'] === $table && ($op === null || $entry['op'] === $op)),
        ))];
    }

    private static function guard(string $table): void
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new LogicException("A merge never changes {$table}.");
        }
    }
}
