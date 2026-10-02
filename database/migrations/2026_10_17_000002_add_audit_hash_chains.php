<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hash chains on the append-only audit tables (Phase 8A.4).
 *
 * Each table gets `chain_seq`, `prev_hash` and `row_hash`:
 *
 *     row_hash = SHA-256(prev_hash || canonical row)
 *
 * computed by a BEFORE INSERT trigger in PostgreSQL. The canonical row is
 * the row as JSONB without prev_hash, row_hash, null values and (for
 * tables whose place follows a moved parent) state_id/lga_id, rendered in
 * UTC with fixed formatting, so it does not depend on session settings
 * and survives adding a nullable column later.
 *
 * Ordering: `audit_chain_heads` holds one row per table (last sequence and
 * hash). The trigger takes that row FOR UPDATE, so concurrent inserts into
 * one table are chained one after another (each waits for the previous
 * inserting transaction to commit); chains of different tables are
 * independent. The trigger function is SECURITY DEFINER (owned by
 * buffalo_owner): buffalo_app cannot write audit_chain_heads itself.
 *
 * Existing rows are chained in id order, in batches.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** table => [timestamp column, columns left out of the hash] */
    private const TABLES = [
        'activity_log' => ['created_at', []],
        'analytics_exports' => ['created_at', []],
        'flashpoint_assessments' => ['assessed_at', ['state_id', 'lga_id']],
        'incident_status_histories' => ['changed_at', ['state_id', 'lga_id']],
        'incident_updates' => ['created_at', ['state_id', 'lga_id']],
        'login_histories' => ['created_at', []],
        'record_access_logs' => ['created_at', []],
        'record_match_events' => ['created_at', []],
        'search_logs' => ['created_at', []],
    ];

    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::create('audit_chain_heads', function (Blueprint $table) {
                $table->string('table_name', 64)->primary();
                $table->bigInteger('last_seq');
                $table->char('last_hash', 64);
                $table->bigInteger('last_row_id')->nullable();
                $table->timestampTz('updated_at');
            });

            Schema::create('audit_anchors', function (Blueprint $table) {
                $table->id();
                $table->string('table_name', 64);
                $table->bigInteger('head_seq');
                $table->bigInteger('head_row_id')->nullable();
                $table->char('head_hash', 64);
                $table->timestampTz('created_at');
                $table->index(['table_name', 'created_at']);
            });
            DB::statement('CREATE TRIGGER audit_anchors_immutable BEFORE UPDATE OR DELETE ON audit_anchors FOR EACH ROW EXECUTE FUNCTION buffalo_reject_audit_change()');
            DB::statement('CREATE TRIGGER audit_anchors_no_truncate BEFORE TRUNCATE ON audit_anchors FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_audit_change()');

            // The canonical form of a row, independent of session settings.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION buffalo_audit_canonical(r anyelement, excluded text[]) RETURNS jsonb
                LANGUAGE sql STABLE
                SET timezone = 'UTC' SET datestyle = 'ISO, YMD' SET intervalstyle = 'postgres' SET extra_float_digits = 1
                AS $$ SELECT jsonb_strip_nulls(to_jsonb(r) - 'prev_hash' - 'row_hash' - excluded) $$;

                CREATE OR REPLACE FUNCTION buffalo_audit_hash(prev text, content jsonb) RETURNS text
                LANGUAGE sql IMMUTABLE
                AS $$ SELECT encode(sha256(convert_to(prev || content::text, 'UTF8')), 'hex') $$;

                CREATE OR REPLACE FUNCTION buffalo_audit_chain() RETURNS trigger
                LANGUAGE plpgsql SECURITY DEFINER
                SET search_path = public, pg_catalog
                AS $$
                DECLARE
                    head record;
                    excluded text[] := CASE WHEN TG_NARGS > 0 AND TG_ARGV[0] <> '' THEN string_to_array(TG_ARGV[0], ',') ELSE '{}'::text[] END;
                BEGIN
                    SELECT last_seq, last_hash INTO head FROM audit_chain_heads WHERE table_name = TG_TABLE_NAME FOR UPDATE;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'No audit chain head for %', TG_TABLE_NAME;
                    END IF;

                    NEW.chain_seq := head.last_seq + 1;
                    NEW.prev_hash := head.last_hash;
                    NEW.row_hash := buffalo_audit_hash(NEW.prev_hash, buffalo_audit_canonical(NEW, excluded));

                    UPDATE audit_chain_heads
                       SET last_seq = NEW.chain_seq, last_hash = NEW.row_hash, last_row_id = NEW.id, updated_at = now()
                     WHERE table_name = TG_TABLE_NAME;

                    RETURN NEW;
                END $$;

                -- Chains existing rows in id order, a batch at a time; returns rows done.
                CREATE OR REPLACE FUNCTION buffalo_audit_backfill(tbl regclass, excluded text[], batch integer) RETURNS integer
                LANGUAGE plpgsql
                AS $$
                DECLARE
                    head record;
                    rec record;
                    seq bigint;
                    prev text;
                    h text;
                    done integer := 0;
                    last_id bigint;
                BEGIN
                    SELECT last_seq, last_hash, last_row_id INTO head FROM audit_chain_heads WHERE table_name = tbl::text FOR UPDATE;
                    seq := head.last_seq;
                    prev := head.last_hash;
                    last_id := head.last_row_id;

                    FOR rec IN EXECUTE format('SELECT id, buffalo_audit_canonical(t, $1) AS content FROM %s t WHERE chain_seq IS NULL ORDER BY id LIMIT $2', tbl)
                        USING excluded, batch
                    LOOP
                        seq := seq + 1;
                        -- Same content as the trigger hashes: the row with its chain_seq.
                        h := buffalo_audit_hash(prev, rec.content || jsonb_build_object('chain_seq', seq));
                        EXECUTE format('UPDATE %s SET chain_seq = $1, prev_hash = $2, row_hash = $3 WHERE id = $4', tbl)
                            USING seq, prev, h, rec.id;
                        prev := h;
                        last_id := rec.id;
                        done := done + 1;
                    END LOOP;

                    UPDATE audit_chain_heads SET last_seq = seq, last_hash = prev, last_row_id = last_id, updated_at = now() WHERE table_name = tbl::text;

                    RETURN done;
                END $$;
                SQL);
            DB::statement('REVOKE ALL ON FUNCTION buffalo_audit_backfill(regclass, text[], integer) FROM PUBLIC');
        });

        foreach (self::TABLES as $table => [$time, $excluded]) {
            DB::transaction(function () use ($table): void {
                Schema::table($table, function (Blueprint $t) {
                    $t->bigInteger('chain_seq')->nullable();
                    $t->char('prev_hash', 64)->nullable();
                    $t->char('row_hash', 64)->nullable();
                });
                DB::statement("CREATE UNIQUE INDEX {$table}_chain_seq_unique ON {$table} (chain_seq)");
                DB::table('audit_chain_heads')->insert(['table_name' => $table, 'last_seq' => 0, 'last_hash' => self::GENESIS, 'updated_at' => now()]);
            });

            $this->backfill($table, $excluded);
        }
    }

    /**
     * @param  list<string>  $excluded
     */
    private function backfill(string $table, array $excluded): void
    {
        $triggers = array_map(fn ($row) => $row->tgname, DB::select(
            'SELECT tgname FROM pg_trigger WHERE tgrelid = ?::regclass AND NOT tgisinternal AND (tgtype & 16) = 16 AND (tgtype & 1) = 1',
            [$table],
        ));
        $array = '{'.implode(',', $excluded).'}';

        $run = function (bool $final) use ($table, $triggers, $array): int {
            return DB::transaction(function () use ($table, $triggers, $array, $final): int {
                if ($final) {
                    // The last batch and the trigger together, so no row is missed.
                    DB::statement("LOCK TABLE {$table} IN EXCLUSIVE MODE");
                }
                foreach ($triggers as $trigger) {
                    DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
                }
                $done = (int) DB::selectOne('SELECT buffalo_audit_backfill(?::regclass, ?::text[], ?) AS done', [$table, $array, $final ? 2147483647 : 5000])->done;
                foreach ($triggers as $trigger) {
                    DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
                }

                return $done;
            });
        };

        while ($run(false) > 0) {
            // Batches of 5,000 rows, each in its own transaction.
        }

        DB::transaction(function () use ($table, $run, $excluded): void {
            $run(true);
            $argument = implode(',', $excluded);
            DB::statement("CREATE TRIGGER zz_{$table}_audit_chain BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION buffalo_audit_chain('{$argument}')");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN chain_seq SET NOT NULL, ALTER COLUMN prev_hash SET NOT NULL, ALTER COLUMN row_hash SET NOT NULL");
        });
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            DB::statement("DROP TRIGGER IF EXISTS zz_{$table}_audit_chain ON {$table}");
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['chain_seq', 'prev_hash', 'row_hash']));
        }

        DB::statement('DROP FUNCTION IF EXISTS buffalo_audit_backfill(regclass, text[], integer)');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_audit_chain()');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_audit_hash(text, jsonb)');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_audit_canonical(anyelement, text[])');
        Schema::dropIfExists('audit_anchors');
        Schema::dropIfExists('audit_chain_heads');
    }
};
