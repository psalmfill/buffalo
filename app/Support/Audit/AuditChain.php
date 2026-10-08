<?php

namespace App\Support\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Verifies the audit hash chains (Phase 8A.4).
 *
 * Every append-only audit table is chained by a BEFORE INSERT trigger:
 * row_hash = SHA-256(prev_hash || canonical row), with a per-table
 * sequence (chain_seq) and head (audit_chain_heads). Walking a chain in
 * sequence order and recomputing each hash in PostgreSQL detects:
 * - an altered row (its recomputed hash differs from row_hash);
 * - a deleted row (a gap in the sequence, or a broken prev_hash link);
 * - deleted rows at the end (the head is further on than the last row).
 * A chain rewritten from some point onwards by someone with full database
 * access is detected by comparing with the nightly anchors kept outside
 * the database.
 */
final class AuditChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** table => [timestamp column, columns left out of the hash] (as in the migration) */
    public const TABLES = [
        'activity_log' => ['created_at', []],
        'analytics_exports' => ['created_at', []],
        'flashpoint_assessments' => ['assessed_at', ['state_id', 'lga_id']],
        'golive_decisions' => ['created_at', []],
        'incident_consolidations' => ['consolidated_at', ['state_id', 'lga_id']],
        'incident_status_histories' => ['changed_at', ['state_id', 'lga_id']],
        'incident_updates' => ['created_at', ['state_id', 'lga_id']],
        'login_histories' => ['created_at', []],
        'person_merges' => ['executed_at', ['survivor_prior_values', 'undone_by', 'undone_at', 'undo_reason']],
        'record_access_logs' => ['created_at', []],
        'record_match_events' => ['created_at', []],
        'search_logs' => ['created_at', []],
    ];

    /**
     * @return array{table: string, ok: bool, checked: int, broken: array{row_id: int|null, chain_seq: int|null, at: string|null, reason: string}|null}
     */
    public static function verify(string $table, ?CarbonImmutable $since = null, int $batch = 20000): array
    {
        [$time, $excluded] = self::TABLES[$table];
        $excludedArray = '{'.implode(',', $excluded).'}';

        $head = DB::table('audit_chain_heads')->where('table_name', $table)->first();
        if ($head === null) {
            return self::result($table, 0, ['row_id' => null, 'chain_seq' => null, 'at' => null, 'reason' => 'the chain head is missing']);
        }

        $start = 1;
        if ($since !== null) {
            $start = (int) (DB::table($table)->where($time, '>=', $since)->min('chain_seq') ?? ((int) $head->last_seq + 1));
        }

        $prevSeq = $start - 1;
        $prevHash = self::GENESIS;
        if ($prevSeq > 0) {
            $previous = DB::table($table)->where('chain_seq', $prevSeq)->value('row_hash');
            if ($previous === null) {
                $first = DB::table($table)->where('chain_seq', $start)->first(['id', $time]);

                return self::result($table, 0, ['row_id' => $first?->id, 'chain_seq' => $start, 'at' => self::at($first?->{$time}), 'reason' => 'the row before this one is missing (deleted)']);
            }
            $prevHash = (string) $previous;
        }

        $checked = 0;
        $cursor = $start;

        while (true) {
            $rows = DB::select(
                "SELECT id, chain_seq, prev_hash, row_hash, {$time} AS at,
                        buffalo_audit_hash(prev_hash, buffalo_audit_canonical(t, ?::text[])) AS expected
                   FROM {$table} t WHERE chain_seq >= ? ORDER BY chain_seq LIMIT ?",
                [$excludedArray, $cursor, $batch],
            );

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $broken = fn (string $reason) => ['row_id' => (int) $row->id, 'chain_seq' => (int) $row->chain_seq, 'at' => self::at($row->at), 'reason' => $reason];

                if ((int) $row->chain_seq !== $prevSeq + 1) {
                    return self::result($table, $checked, $broken('one or more rows before this one are missing (deleted)'));
                }
                if ($row->prev_hash !== $prevHash) {
                    return self::result($table, $checked, $broken('the link to the previous row does not match (the previous row was altered or removed)'));
                }
                if ($row->expected !== $row->row_hash) {
                    return self::result($table, $checked, $broken('the row was altered'));
                }

                $prevSeq = (int) $row->chain_seq;
                $prevHash = (string) $row->row_hash;
                $checked++;
            }

            $cursor = $prevSeq + 1;
        }

        if ((int) $head->last_seq !== $prevSeq || $head->last_hash !== $prevHash) {
            return self::result($table, $checked, [
                'row_id' => $head->last_row_id === null ? null : (int) $head->last_row_id,
                'chain_seq' => (int) $head->last_seq,
                'at' => null,
                'reason' => (int) $head->last_seq > $prevSeq ? 'the last rows of the chain are missing (deleted)' : 'the chain head does not match the last row',
            ]);
        }

        return self::result($table, $checked, null);
    }

    /**
     * Records each chain's current head (nightly), for copying to an
     * external record.
     *
     * @return list<array{table: string, head_seq: int, head_row_id: int|null, head_hash: string}>
     */
    public static function anchor(): array
    {
        $now = now();
        $anchors = [];

        foreach (DB::table('audit_chain_heads')->orderBy('table_name')->get() as $head) {
            $anchor = ['table' => (string) $head->table_name, 'head_seq' => (int) $head->last_seq, 'head_row_id' => $head->last_row_id === null ? null : (int) $head->last_row_id, 'head_hash' => (string) $head->last_hash];
            DB::table('audit_anchors')->insert(['table_name' => $anchor['table'], 'head_seq' => $anchor['head_seq'], 'head_row_id' => $anchor['head_row_id'], 'head_hash' => $anchor['head_hash'], 'created_at' => $now]);
            $anchors[] = $anchor;
        }

        return $anchors;
    }

    /**
     * @param  array{row_id: int|null, chain_seq: int|null, at: string|null, reason: string}|null  $broken
     * @return array{table: string, ok: bool, checked: int, broken: array{row_id: int|null, chain_seq: int|null, at: string|null, reason: string}|null}
     */
    private static function result(string $table, int $checked, ?array $broken): array
    {
        return ['table' => $table, 'ok' => $broken === null, 'checked' => $checked, 'broken' => $broken];
    }

    private static function at(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s T');
    }
}
