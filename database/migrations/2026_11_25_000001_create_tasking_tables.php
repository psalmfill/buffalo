<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11H: taskings, written instructions from a State Director or Deputy
 * to the LGA Security Chiefs of their state.
 *
 * - `taskings`: the sender's state; a draft keeps its chosen LGAs in
 *   `draft_lga_ids` (recipients are created on send). Once sent, the title,
 *   body, priority, due date, classification and recipients are fixed
 *   (trigger); a sent tasking can only be recalled, with a reason.
 *   References TSK/<STATE>/<YEAR>/<5 digits> are assigned on send, gap-free
 *   per state and year (`tasking_counters`, as for threat reports).
 * - `tasking_recipients`: one row per LGA, created in the sending
 *   transaction only, with their own state_id/lga_id (composite FK, access
 *   rule 9). Statuses only move forward; the completion report is fixed.
 * - `tasking_notes` (follow-up notes by the directors) and
 *   `tasking_responses` (further reports back by an LGA after completing):
 *   append-only.
 * - `tasking_attachments`: stored and served like security report
 *   attachments (Phase 11G) on the evidence disk. Attachments of the
 *   tasking carry its state only and are added while it is a draft;
 *   attachments of a response carry the recipient's state and LGA.
 */
return new class extends Migration
{
    /** Report run types before this step (Phase 11F); `tasking` is added. */
    private const REPORT_TYPES = ['sitrep', 'weekly', 'monthly', 'incident_list', 'incident', 'lga_profile', 'coverage', 'analytics', 'list', 'flashpoint', 'organization_site', 'arrest', 'threat_report', 'criminal_group', 'person', 'state_profile'];

    public function up(): void
    {
        // The tasking PDF (record report type `tasking`).
        DB::statement('ALTER TABLE report_runs DROP CONSTRAINT report_runs_type');
        DB::statement('ALTER TABLE report_runs ADD CONSTRAINT report_runs_type CHECK (report_type IN ('.self::quoted([...self::REPORT_TYPES, 'tasking']).'))');

        Schema::create('tasking_counters', function (Blueprint $table) {
            $table->foreignId('state_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_value');
            $table->primary(['state_id', 'year']);
        });

        Schema::create('taskings', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('reference_no', 40)->nullable()->unique();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->string('title', 200)->nullable();
            $table->text('body')->nullable();
            $table->text('body_text')->nullable();
            $table->string('priority', 20)->default('routine');
            $table->timestampTz('response_due_at')->nullable();
            $table->string('target_mode', 20)->nullable();
            $table->jsonb('draft_lga_ids')->nullable();
            $table->string('classification', 20)->default('restricted');
            $table->string('status', 20)->default('draft');
            $table->foreignId('sent_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->foreignId('recalled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('recalled_at')->nullable();
            $table->text('recall_reason')->nullable();
            $table->date('overdue_summary_sent_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['state_id', 'status', 'sent_at']);
            $table->index('response_due_at');
        });

        DB::statement("ALTER TABLE taskings ADD CONSTRAINT taskings_priority_valid CHECK (priority IN ('routine', 'priority', 'immediate'))");
        DB::statement("ALTER TABLE taskings ADD CONSTRAINT taskings_status_valid CHECK (status IN ('draft', 'sent', 'recalled'))");
        DB::statement("ALTER TABLE taskings ADD CONSTRAINT taskings_target_mode_valid CHECK (target_mode IS NULL OR target_mode IN ('single', 'selected', 'all_lgas'))");
        DB::statement("ALTER TABLE taskings ADD CONSTRAINT taskings_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement("ALTER TABLE taskings ADD CONSTRAINT taskings_draft_lgas_array CHECK (draft_lga_ids IS NULL OR jsonb_typeof(draft_lga_ids) = 'array')");
        DB::statement("ALTER TABLE taskings ADD CONSTRAINT taskings_sent_complete CHECK (status = 'draft' OR (reference_no IS NOT NULL AND sent_at IS NOT NULL AND sent_by IS NOT NULL AND target_mode IS NOT NULL AND body IS NOT NULL AND draft_lga_ids IS NULL))");
        DB::statement("ALTER TABLE taskings ADD CONSTRAINT taskings_recall_complete CHECK (status <> 'recalled' OR (recalled_at IS NOT NULL AND recalled_by IS NOT NULL AND length(trim(recall_reason)) > 0))");
        // Only drafts are ever deleted (soft).
        DB::statement("ALTER TABLE taskings ADD CONSTRAINT taskings_only_drafts_deleted CHECK (deleted_at IS NULL OR status = 'draft')");

        Schema::create('tasking_recipients', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tasking_id')->constrained()->restrictOnDelete();
            $table->foreignId('state_id')->index();
            $table->foreignId('lga_id')->index();
            $table->string('status', 20)->default('delivered');
            $table->timestampTz('read_at')->nullable();
            $table->foreignId('read_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('response')->nullable();
            $table->text('response_text')->nullable();
            $table->timestampTz('overdue_notified_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tasking_id', 'lga_id']);
            // For the attachments' composite key (same place as the row).
            $table->unique(['id', 'lga_id', 'state_id']);
            $table->index(['lga_id', 'status']);
            $table->foreign(['lga_id', 'state_id'], 'tasking_recipients_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE tasking_recipients ADD CONSTRAINT tasking_recipients_status_valid CHECK (status IN ('delivered', 'read', 'acknowledged', 'completed'))");
        DB::statement("ALTER TABLE tasking_recipients ADD CONSTRAINT tasking_recipients_read_complete CHECK (status = 'delivered' OR (read_at IS NOT NULL AND read_by IS NOT NULL))");
        DB::statement("ALTER TABLE tasking_recipients ADD CONSTRAINT tasking_recipients_ack_complete CHECK (status NOT IN ('acknowledged', 'completed') OR (acknowledged_at IS NOT NULL AND acknowledged_by IS NOT NULL))");
        DB::statement("ALTER TABLE tasking_recipients ADD CONSTRAINT tasking_recipients_completed_complete CHECK (status <> 'completed' OR (completed_at IS NOT NULL AND completed_by IS NOT NULL AND response IS NOT NULL AND length(trim(response_text)) > 0))");

        foreach (['tasking_notes', 'tasking_responses'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->ulid('ulid')->unique();
                $table->foreignId('tasking_id')->index()->constrained()->restrictOnDelete();
                $table->foreignId('state_id')->index();
                if ($name === 'tasking_responses') {
                    $table->foreignId('tasking_recipient_id')->index();
                    $table->foreignId('lga_id')->index();
                    $table->foreign(['tasking_recipient_id', 'lga_id', 'state_id'], 'tasking_responses_recipient_place_foreign')
                        ->references(['id', 'lga_id', 'state_id'])->on('tasking_recipients')->restrictOnDelete()->restrictOnUpdate();
                } else {
                    $table->foreign('state_id')->references('id')->on('states')->restrictOnDelete();
                }
                $table->text('body');
                $table->text('body_text');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestampTz('created_at');
            });

            DB::statement("CREATE TRIGGER {$name}_append_only BEFORE UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION buffalo_reject_audit_change()");
            DB::statement("CREATE TRIGGER {$name}_no_truncate BEFORE TRUNCATE ON {$name} FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_audit_change()");
        }

        Schema::create('tasking_attachments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tasking_id')->index()->constrained()->restrictOnDelete();
            // Null: an attachment of the tasking itself; else of that LGA's report back.
            $table->foreignId('tasking_recipient_id')->nullable()->index();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->nullable()->index();
            $table->string('caption', 200)->nullable();
            $table->string('media_type', 10);
            $table->string('original_filename', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestampTz('captured_at')->nullable();
            $table->geography('captured_location', subtype: 'point', srid: 4326)->nullable();
            $table->jsonb('exif_summary')->nullable();
            $table->string('classification', 20)->default('restricted');
            $table->string('scan_status', 20)->default('quarantined');
            $table->timestampTz('scanned_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->foreignId('uploaded_by')->index()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('scan_status');
            $table->foreign(['tasking_recipient_id', 'lga_id', 'state_id'], 'tasking_attachments_recipient_place_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('tasking_recipients')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['lga_id', 'state_id'], 'tasking_attachments_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE tasking_attachments ADD CONSTRAINT tasking_attachments_media_type_valid CHECK (media_type IN ('image', 'video', 'document'))");
        DB::statement("ALTER TABLE tasking_attachments ADD CONSTRAINT tasking_attachments_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement("ALTER TABLE tasking_attachments ADD CONSTRAINT tasking_attachments_scan_status_valid CHECK (scan_status IN ('clean', 'quarantined', 'infected'))");
        DB::statement("ALTER TABLE tasking_attachments ADD CONSTRAINT tasking_attachments_sha256_hex CHECK (sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE tasking_attachments ADD CONSTRAINT tasking_attachments_size_positive CHECK (size_bytes > 0)');
        DB::statement("ALTER TABLE tasking_attachments ADD CONSTRAINT tasking_attachments_exif_object CHECK (exif_summary IS NULL OR jsonb_typeof(exif_summary) = 'object')");
        // An attachment of the tasking has no LGA; one of a response has the recipient's.
        DB::statement('ALTER TABLE tasking_attachments ADD CONSTRAINT tasking_attachments_place_matches_owner CHECK ((tasking_recipient_id IS NULL) = (lga_id IS NULL))');

        // Fixed once sent: the instruction, its level and its recipients.
        // Status moves draft → sent → recalled only.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_tasking_fixed_after_send() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'taskings are never deleted' USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status <> 'draft' AND (
                    NEW.title IS DISTINCT FROM OLD.title
                    OR NEW.body IS DISTINCT FROM OLD.body
                    OR NEW.body_text IS DISTINCT FROM OLD.body_text
                    OR NEW.priority IS DISTINCT FROM OLD.priority
                    OR NEW.response_due_at IS DISTINCT FROM OLD.response_due_at
                    OR NEW.classification IS DISTINCT FROM OLD.classification
                    OR NEW.target_mode IS DISTINCT FROM OLD.target_mode
                    OR NEW.state_id IS DISTINCT FROM OLD.state_id
                    OR NEW.reference_no IS DISTINCT FROM OLD.reference_no
                    OR NEW.sent_at IS DISTINCT FROM OLD.sent_at
                    OR NEW.sent_by IS DISTINCT FROM OLD.sent_by
                    OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
                ) THEN
                    RAISE EXCEPTION 'a sent tasking cannot be changed' USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'draft' AND NEW.status = 'sent')
                    OR (OLD.status = 'sent' AND NEW.status = 'recalled')
                ) THEN
                    RAISE EXCEPTION 'invalid tasking status change' USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status = 'recalled' AND (
                    NEW.recalled_at IS DISTINCT FROM OLD.recalled_at
                    OR NEW.recalled_by IS DISTINCT FROM OLD.recalled_by
                    OR NEW.recall_reason IS DISTINCT FROM OLD.recall_reason
                ) THEN
                    RAISE EXCEPTION 'a recall cannot be changed' USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status = 'draft' AND NEW.status = 'sent' AND NOT EXISTS (
                    SELECT 1 FROM tasking_recipients WHERE tasking_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'a tasking is sent to at least one LGA' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END $$
            SQL);
        DB::statement('CREATE TRIGGER taskings_fixed_after_send BEFORE UPDATE OR DELETE ON taskings FOR EACH ROW EXECUTE FUNCTION buffalo_tasking_fixed_after_send()');

        // Recipients are created in the sending transaction only (while the
        // tasking is still a draft), always in the tasking's state, never
        // removed; statuses move forward; the completion report is fixed;
        // nothing is acknowledged or completed after a recall.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_tasking_recipient_rules() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                parent record;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'tasking recipients are never removed' USING ERRCODE = 'check_violation';
                END IF;

                SELECT status, state_id INTO parent FROM taskings WHERE id = NEW.tasking_id;

                IF TG_OP = 'INSERT' THEN
                    IF parent.status <> 'draft' THEN
                        RAISE EXCEPTION 'recipients are fixed once a tasking is sent' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.state_id <> parent.state_id THEN
                        RAISE EXCEPTION 'a tasking is sent to LGAs of its own state only' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.tasking_id IS DISTINCT FROM OLD.tasking_id
                    OR NEW.lga_id IS DISTINCT FROM OLD.lga_id
                    OR NEW.state_id IS DISTINCT FROM OLD.state_id THEN
                    RAISE EXCEPTION 'recipients are fixed once a tasking is sent' USING ERRCODE = 'check_violation';
                END IF;

                IF array_position(ARRAY['delivered', 'read', 'acknowledged', 'completed'], NEW.status)
                    < array_position(ARRAY['delivered', 'read', 'acknowledged', 'completed'], OLD.status) THEN
                    RAISE EXCEPTION 'a recipient status cannot move back' USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status = 'completed' AND (
                    NEW.response IS DISTINCT FROM OLD.response
                    OR NEW.response_text IS DISTINCT FROM OLD.response_text
                    OR NEW.completed_at IS DISTINCT FROM OLD.completed_at
                    OR NEW.completed_by IS DISTINCT FROM OLD.completed_by
                ) THEN
                    RAISE EXCEPTION 'a completion report cannot be changed' USING ERRCODE = 'check_violation';
                END IF;

                IF parent.status = 'recalled' AND NEW.status IN ('acknowledged', 'completed') AND NEW.status IS DISTINCT FROM OLD.status THEN
                    RAISE EXCEPTION 'a recalled tasking cannot be acknowledged or completed' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END $$
            SQL);
        DB::statement('CREATE TRIGGER tasking_recipients_rules BEFORE INSERT OR UPDATE OR DELETE ON tasking_recipients FOR EACH ROW EXECUTE FUNCTION buffalo_tasking_recipient_rules()');
        DB::statement('CREATE TRIGGER tasking_recipients_no_truncate BEFORE TRUNCATE ON tasking_recipients FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_audit_change()');

        // Attachments: never below the tasking's level; the tasking's own
        // only while it is a draft; a response's only while the tasking is
        // sent (not recalled). Removal is a soft delete. The malware scan and
        // integrity processing (other columns) may always finish.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_tasking_attachment_rules() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                parent record;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'tasking attachments are never deleted' USING ERRCODE = 'check_violation';
                END IF;

                SELECT status, state_id, classification INTO parent FROM taskings WHERE id = NEW.tasking_id;

                IF NEW.state_id <> parent.state_id THEN
                    RAISE EXCEPTION 'an attachment belongs to the tasking''s state' USING ERRCODE = 'check_violation';
                END IF;

                IF buffalo_classification_rank(NEW.classification) < buffalo_classification_rank(parent.classification) THEN
                    RAISE EXCEPTION 'An attachment cannot be classified below its tasking.' USING ERRCODE = 'check_violation';
                END IF;

                IF TG_OP = 'UPDATE' AND (
                    NEW.tasking_id IS DISTINCT FROM OLD.tasking_id
                    OR NEW.tasking_recipient_id IS DISTINCT FROM OLD.tasking_recipient_id
                ) THEN
                    RAISE EXCEPTION 'an attachment cannot move' USING ERRCODE = 'check_violation';
                END IF;

                IF TG_OP = 'INSERT' OR NEW.caption IS DISTINCT FROM OLD.caption
                    OR NEW.classification IS DISTINCT FROM OLD.classification
                    OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at THEN
                    IF NEW.tasking_recipient_id IS NULL AND parent.status <> 'draft' THEN
                        RAISE EXCEPTION 'a sent tasking cannot be changed' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.tasking_recipient_id IS NOT NULL AND parent.status <> 'sent' THEN
                        RAISE EXCEPTION 'reports back are added to a sent tasking only' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END $$
            SQL);
        DB::statement('CREATE TRIGGER tasking_attachments_rules BEFORE INSERT OR UPDATE OR DELETE ON tasking_attachments FOR EACH ROW EXECUTE FUNCTION buffalo_tasking_attachment_rules()');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE report_runs DROP CONSTRAINT report_runs_type');
        DB::statement('ALTER TABLE report_runs ADD CONSTRAINT report_runs_type CHECK (report_type IN ('.self::quoted(self::REPORT_TYPES).'))');

        Schema::dropIfExists('tasking_attachments');
        Schema::dropIfExists('tasking_responses');
        Schema::dropIfExists('tasking_notes');
        Schema::dropIfExists('tasking_recipients');
        Schema::dropIfExists('taskings');
        Schema::dropIfExists('tasking_counters');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_tasking_attachment_rules()');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_tasking_recipient_rules()');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_tasking_fixed_after_send()');
    }

    /**
     * @param  list<string>  $values
     */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => "'{$v}'", $values));
    }
};
