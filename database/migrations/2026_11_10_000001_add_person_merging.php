<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merging duplicate persons (Phase 10A.2).
 *
 * - persons: merged_into_person_id / merged_at / merged_by, set only on
 *   soft-deleted rows (CHECK). A merged person is never a survivor (the
 *   person_merges insert trigger refuses it).
 * - persons_of_interest.merged_into_poi_id: a source POI row combined into
 *   the survivor's.
 * - person_merges: one row per merge with its manifest. Append-only and
 *   hash-chained, except the undo columns (set once) and
 *   survivor_prior_values (erased to NULL; replaced only during an APP_KEY
 *   rotation). Those four columns are left out of the hash.
 * - person_merge_requests and person_duplicate_dismissals.
 * - incident_persons gets its own lock trigger: identical to
 *   buffalo_incident_child_locked, plus the one documented exception for
 *   merges (see buffalo_incident_persons_locked below).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->foreignId('merged_into_person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $table->timestampTz('merged_at')->nullable();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('merged_into_person_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE persons ADD CONSTRAINT persons_merged_only_when_deleted
                CHECK ((merged_into_person_id IS NULL AND merged_at IS NULL)
                    OR (merged_into_person_id IS NOT NULL AND merged_at IS NOT NULL AND deleted_at IS NOT NULL AND merged_into_person_id <> id))
            SQL);

        Schema::table('persons_of_interest', function (Blueprint $table) {
            $table->foreignId('merged_into_poi_id')->nullable()->constrained('persons_of_interest')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE persons_of_interest ADD CONSTRAINT persons_of_interest_merged_only_when_deleted
                CHECK (merged_into_poi_id IS NULL OR (deleted_at IS NOT NULL AND merged_into_poi_id <> id))
            SQL);

        Schema::create('person_merge_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->constrained('states')->restrictOnDelete();
            // The requester's LGA (LGA chiefs); null for a Deputy State Director.
            $table->foreignId('lga_id')->nullable()->constrained('lgas')->restrictOnDelete();
            $table->foreignId('person_a_id')->constrained('persons')->restrictOnDelete();
            $table->foreignId('person_b_id')->constrained('persons')->restrictOnDelete();
            $table->foreignId('suggested_survivor_id')->nullable()->constrained('persons')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();
            $table->index(['state_id', 'status']);
            $table->foreign(['lga_id', 'state_id'], 'person_merge_requests_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE person_merge_requests
                ADD CONSTRAINT person_merge_requests_distinct CHECK (person_a_id <> person_b_id),
                ADD CONSTRAINT person_merge_requests_reason_length CHECK (char_length(btrim(reason)) >= 20),
                ADD CONSTRAINT person_merge_requests_status CHECK (status IN ('pending', 'executed', 'declined', 'cancelled')),
                ADD CONSTRAINT person_merge_requests_suggested CHECK (suggested_survivor_id IS NULL OR suggested_survivor_id IN (person_a_id, person_b_id))
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX person_merge_requests_one_pending ON person_merge_requests
                (LEAST(person_a_id, person_b_id), GREATEST(person_a_id, person_b_id)) WHERE status = 'pending'
            SQL);

        Schema::create('person_duplicate_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_a_id')->constrained('persons')->restrictOnDelete();
            $table->foreignId('person_b_id')->constrained('persons')->restrictOnDelete();
            $table->foreignId('dismissed_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestampTz('created_at');
            $table->unique(['person_a_id', 'person_b_id']);
            $table->index('person_b_id');
        });

        DB::statement('ALTER TABLE person_duplicate_dismissals ADD CONSTRAINT person_duplicate_dismissals_ordered CHECK (person_a_id < person_b_id)');

        Schema::create('person_merges', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->constrained('states')->restrictOnDelete();
            $table->foreignId('survivor_person_id')->constrained('persons')->restrictOnDelete();
            $table->foreignId('source_person_id')->constrained('persons')->restrictOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('person_merge_requests')->restrictOnDelete();
            // "survivor" or "source" per conflicting field, never the values.
            $table->jsonb('field_choices');
            $table->jsonb('manifest');
            // Crypt-encrypted JSON of the survivor's fields and place before
            // (and after) the merge; erased once the undo window has passed.
            $table->text('survivor_prior_values')->nullable();
            $table->foreignId('executed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('executed_at');
            $table->foreignId('undone_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('undone_at')->nullable();
            $table->text('undo_reason')->nullable();
            $table->bigInteger('chain_seq');
            $table->char('prev_hash', 64);
            $table->char('row_hash', 64);
            $table->unique('chain_seq');
            $table->index('survivor_person_id');
            $table->index('source_person_id');
            $table->index('executed_at');
        });

        DB::statement('ALTER TABLE person_merges ADD CONSTRAINT person_merges_distinct CHECK (survivor_person_id <> source_person_id)');
        DB::statement('ALTER TABLE person_merges ADD CONSTRAINT person_merges_undo_complete CHECK ((undone_at IS NULL) = (undone_by IS NULL) AND (undone_at IS NULL) = (undo_reason IS NULL))');

        DB::table('audit_chain_heads')->insert([
            'table_name' => 'person_merges', 'last_seq' => 0,
            'last_hash' => str_repeat('0', 64), 'updated_at' => now(),
        ]);

        DB::unprepared(<<<'SQL'
            -- person_merges: append-only, except
            --   * undone_by / undone_at / undo_reason: set once (NULL -> value);
            --   * survivor_prior_values: erased (-> NULL), or replaced while
            --     non-null only during an APP_KEY rotation
            --     (transaction-local buffalo.key_rotation = 'on').
            CREATE OR REPLACE FUNCTION buffalo_person_merges_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                mutable text[] := ARRAY['undone_by', 'undone_at', 'undo_reason', 'survivor_prior_values'];
            BEGIN
                IF TG_OP <> 'UPDATE' THEN
                    RAISE EXCEPTION 'person_merges is append-only: % is not allowed', TG_OP USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF (to_jsonb(NEW) - mutable) IS DISTINCT FROM (to_jsonb(OLD) - mutable) THEN
                    RAISE EXCEPTION 'person_merges is append-only: only the undo columns may be set' USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.undone_at IS NOT NULL AND (NEW.undone_at, NEW.undone_by, NEW.undo_reason) IS DISTINCT FROM (OLD.undone_at, OLD.undone_by, OLD.undo_reason) THEN
                    RAISE EXCEPTION 'person_merges: a merge is undone only once' USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF NEW.survivor_prior_values IS DISTINCT FROM OLD.survivor_prior_values
                   AND NEW.survivor_prior_values IS NOT NULL
                   AND NOT (OLD.survivor_prior_values IS NOT NULL AND coalesce(current_setting('buffalo.key_rotation', true), '') = 'on') THEN
                    RAISE EXCEPTION 'person_merges: survivor_prior_values may only be erased' USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END $$;

            CREATE TRIGGER person_merges_guard BEFORE UPDATE OR DELETE ON person_merges
                FOR EACH ROW EXECUTE FUNCTION buffalo_person_merges_guard();
            CREATE TRIGGER person_merges_no_truncate BEFORE TRUNCATE ON person_merges
                FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_audit_change();

            -- Neither person may already be merged when a merge is recorded:
            -- a merged person is never the survivor of a later merge.
            CREATE OR REPLACE FUNCTION buffalo_person_merges_check_persons() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM persons WHERE id = NEW.survivor_person_id AND (merged_into_person_id IS NOT NULL OR deleted_at IS NOT NULL)) THEN
                    RAISE EXCEPTION 'A merged or deleted person cannot be the survivor of a merge.' USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM persons WHERE id = NEW.source_person_id AND merged_into_person_id = NEW.survivor_person_id) THEN
                    RAISE EXCEPTION 'The source must be marked as merged into the survivor first.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER person_merges_check_persons BEFORE INSERT ON person_merges
                FOR EACH ROW EXECUTE FUNCTION buffalo_person_merges_check_persons();
            CREATE TRIGGER zz_person_merges_audit_chain BEFORE INSERT ON person_merges
                FOR EACH ROW EXECUTE FUNCTION buffalo_audit_chain('survivor_prior_values,undone_by,undone_at,undo_reason');

            -- incident_persons on a closed or invalid incident are locked
            -- (Phase 5B), with ONE exception (Phase 10A.4): while a person
            -- merge or its undo runs, the action sets the transaction-local
            -- setting buffalo.person_merge to the merge's ULID, and then an
            -- UPDATE may change person_id, notes, deleted_at and updated_at
            -- only (repointing, combining notes, dropping a duplicate row).
            -- Inserts, deletes, a move to another incident and any other
            -- change stay refused.
            CREATE OR REPLACE FUNCTION buffalo_incident_persons_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                incident_status text;
                merge_columns text[] := ARRAY['person_id', 'notes', 'deleted_at', 'updated_at'];
            BEGIN
                SELECT status INTO incident_status FROM incidents WHERE id = COALESCE(NEW.incident_id, OLD.incident_id);
                IF incident_status IN ('closed', 'invalid') THEN
                    IF TG_OP = 'UPDATE'
                       AND coalesce(current_setting('buffalo.person_merge', true), '') <> ''
                       AND (to_jsonb(NEW) - merge_columns) = (to_jsonb(OLD) - merge_columns) THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'The incident is %: it is locked.', incident_status USING ERRCODE = 'check_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;

            DROP TRIGGER incident_persons_incident_locked ON incident_persons;
            CREATE TRIGGER incident_persons_incident_locked BEFORE INSERT OR UPDATE OR DELETE ON incident_persons
                FOR EACH ROW EXECUTE FUNCTION buffalo_incident_persons_locked();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER incident_persons_incident_locked ON incident_persons;
            CREATE TRIGGER incident_persons_incident_locked BEFORE INSERT OR UPDATE OR DELETE ON incident_persons
                FOR EACH ROW EXECUTE FUNCTION buffalo_incident_child_locked();
            DROP FUNCTION buffalo_incident_persons_locked();
            SQL);

        Schema::dropIfExists('person_merges');
        DB::table('audit_chain_heads')->where('table_name', 'person_merges')->delete();
        DB::unprepared('DROP FUNCTION IF EXISTS buffalo_person_merges_guard(); DROP FUNCTION IF EXISTS buffalo_person_merges_check_persons();');
        Schema::dropIfExists('person_duplicate_dismissals');
        Schema::dropIfExists('person_merge_requests');

        DB::statement('ALTER TABLE persons_of_interest DROP CONSTRAINT persons_of_interest_merged_only_when_deleted');
        Schema::table('persons_of_interest', fn (Blueprint $table) => $table->dropConstrainedForeignId('merged_into_poi_id'));
        DB::statement('ALTER TABLE persons DROP CONSTRAINT persons_merged_only_when_deleted');
        Schema::table('persons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_by');
            $table->dropColumn('merged_at');
            $table->dropConstrainedForeignId('merged_into_person_id');
        });
    }
};
