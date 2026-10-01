<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The incident workflow (Phase 5C).
 *
 * - Workflow and escalation columns on `incidents`.
 * - `incident_reference_counters`: one row per state and year, incremented
 *   inside the submission transaction (gap-free reference numbers).
 * - `incident_status_histories` and `incident_updates` (follow-ups): append-
 *   only. The only change allowed is the place following the incident when
 *   its LGA is corrected (composite FK, ON UPDATE CASCADE).
 * - Closed and invalid incidents are locked: the incident itself, its
 *   related rows, its evidence and new follow-ups are refused.
 * - `incident_reminders`: verification reminders already sent.
 */
return new class extends Migration
{
    private const LOCKED_CHILDREN = ['incident_casualties', 'incident_persons', 'incident_criminal_groups', 'incident_agencies', 'incident_properties'];

    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            foreach (['submitted', 'returned', 'verified', 'investigation_started', 'resolved', 'closed', 'invalidated', 'escalated', 'escalation_acknowledged'] as $step) {
                $table->foreignId("{$step}_by")->nullable()->constrained('users')->restrictOnDelete();
                $table->timestampTz("{$step}_at")->nullable();
            }
            $table->text('return_reason')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->string('invalid_reason', 30)->nullable();
            $table->text('invalid_note')->nullable();
            $table->foreignId('duplicate_of_incident_id')->nullable()->constrained('incidents')->restrictOnDelete();
            $table->text('escalation_reason')->nullable();

            $table->index(['status', 'submitted_at']);
            $table->index('escalated_at');
        });

        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_invalid_reason_valid CHECK (invalid_reason IS NULL OR invalid_reason IN ('duplicate', 'false_report', 'not_security_related', 'other'))");
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_duplicate_needs_link CHECK (invalid_reason IS DISTINCT FROM 'duplicate' OR duplicate_of_incident_id IS NOT NULL)");
        DB::statement('ALTER TABLE incidents ADD CONSTRAINT incidents_not_duplicate_of_itself CHECK (duplicate_of_incident_id IS DISTINCT FROM id)');
        DB::statement('ALTER TABLE incidents ADD CONSTRAINT incidents_reference_after_submission CHECK (reference_no IS NULL OR submitted_at IS NOT NULL)');
        // A reference is given once and never taken back.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS incidents_reference_no_ci ON incidents (lower(reference_no)) WHERE reference_no IS NOT NULL');

        Schema::create('incident_reference_counters', function (Blueprint $table) {
            $table->foreignId('state_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_value');
            $table->primary(['state_id', 'year']);
        });

        Schema::create('incident_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->index();
            $table->foreignId('state_id')->index();
            $table->foreignId('lga_id')->index();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('comment')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at');

            $table->foreign(['incident_id', 'lga_id', 'state_id'], 'incident_status_histories_incident_place_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('incidents')->restrictOnDelete()->cascadeOnUpdate();
        });

        Schema::create('incident_updates', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('incident_id')->index();
            $table->foreignId('state_id')->index();
            $table->foreignId('lga_id')->index();
            $table->string('type', 20);
            $table->text('body');
            $table->string('classification', 20)->default('restricted');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->foreign(['incident_id', 'lga_id', 'state_id'], 'incident_updates_incident_place_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('incidents')->restrictOnDelete()->cascadeOnUpdate();
        });
        DB::statement("ALTER TABLE incident_updates ADD CONSTRAINT incident_updates_type_valid CHECK (type IN ('follow_up', 'action_taken', 'intelligence', 'status_note'))");
        DB::statement("ALTER TABLE incident_updates ADD CONSTRAINT incident_updates_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement('ALTER TABLE incident_updates ADD CONSTRAINT incident_updates_body_present CHECK (length(btrim(body)) > 0)');

        Schema::create('incident_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->timestampTz('for_submission_at');
            $table->timestampTz('sent_at');
            $table->unique(['incident_id', 'kind', 'for_submission_at'], 'incident_reminders_once');
        });

        // Append-only, except the place following its incident.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_append_only_follow_place() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = 'check_violation';
                END IF;
                IF (to_jsonb(NEW) - 'state_id' - 'lga_id') IS DISTINCT FROM (to_jsonb(OLD) - 'state_id' - 'lga_id') THEN
                    RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
            SQL);

        foreach (['incident_status_histories', 'incident_updates'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION buffalo_append_only_follow_place()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_audit_change()");
        }

        // A follow-up is never classified below its incident when written.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_update_not_below_incident() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF buffalo_classification_rank(NEW.classification) < (
                    SELECT buffalo_classification_rank(classification) FROM incidents WHERE id = NEW.incident_id
                ) THEN
                    RAISE EXCEPTION 'A follow-up cannot be classified below its incident.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        DB::statement('CREATE TRIGGER incident_updates_not_below_incident BEFORE INSERT ON incident_updates FOR EACH ROW EXECUTE FUNCTION buffalo_update_not_below_incident()');

        // Closed and invalid incidents are locked.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_incident_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status IN ('closed', 'invalid') THEN
                    RAISE EXCEPTION 'A % incident is locked.', OLD.status USING ERRCODE = 'check_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$
            SQL);
        DB::statement('CREATE TRIGGER incidents_locked BEFORE UPDATE OR DELETE ON incidents FOR EACH ROW EXECUTE FUNCTION buffalo_incident_locked()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_incident_child_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                incident_status text;
            BEGIN
                SELECT status INTO incident_status FROM incidents WHERE id = COALESCE(NEW.incident_id, OLD.incident_id);
                IF incident_status IN ('closed', 'invalid') THEN
                    RAISE EXCEPTION 'The incident is %: it is locked.', incident_status USING ERRCODE = 'check_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$
            SQL);

        foreach (self::LOCKED_CHILDREN as $table) {
            DB::statement("CREATE TRIGGER {$table}_incident_locked BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION buffalo_incident_child_locked()");
        }
        // Evidence: new items, edits and removal; the queued verification
        // (processed_at) may still finish.
        DB::statement('CREATE TRIGGER incident_evidence_incident_locked BEFORE INSERT OR DELETE OR UPDATE OF title, description, classification, captured_at, captured_location, deleted_at, incident_id ON incident_evidence FOR EACH ROW EXECUTE FUNCTION buffalo_incident_child_locked()');
        DB::statement('CREATE TRIGGER incident_updates_incident_locked BEFORE INSERT ON incident_updates FOR EACH ROW EXECUTE FUNCTION buffalo_incident_child_locked()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS incident_evidence_incident_locked ON incident_evidence');
        foreach (self::LOCKED_CHILDREN as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_incident_locked ON {$table}");
        }
        DB::statement('DROP TRIGGER IF EXISTS incidents_locked ON incidents');
        Schema::dropIfExists('incident_reminders');
        Schema::dropIfExists('incident_updates');
        Schema::dropIfExists('incident_status_histories');
        Schema::dropIfExists('incident_reference_counters');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_incident_child_locked()');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_incident_locked()');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_update_not_below_incident()');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_append_only_follow_place()');
        DB::statement('DROP INDEX IF EXISTS incidents_reference_no_ci');

        foreach (['incidents_invalid_reason_valid', 'incidents_duplicate_needs_link', 'incidents_not_duplicate_of_itself', 'incidents_reference_after_submission'] as $constraint) {
            DB::statement("ALTER TABLE incidents DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_incident_id');
            foreach (['submitted', 'returned', 'verified', 'investigation_started', 'resolved', 'closed', 'invalidated', 'escalated', 'escalation_acknowledged'] as $step) {
                $table->dropConstrainedForeignId("{$step}_by");
                $table->dropColumn("{$step}_at");
            }
            $table->dropColumn(['return_reason', 'resolution_summary', 'invalid_reason', 'invalid_note', 'escalation_reason']);
        });
    }
};
