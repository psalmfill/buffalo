<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11G.2: coverage reports become "security reports" in the interface
 * (the table keeps its name). The written report (the existing `summary`
 * column, now labelled "Report") is the only field needed to submit:
 *
 * - `title` (optional) and `report_date` (defaults to the day it was
 *   written; existing rows take the day they were submitted, else created);
 * - a submitted report no longer needs a rating, only its text;
 * - `coverage_report_attachments`: photos and videos, stored like incident
 *   evidence (Phase 5B) on the private evidence disk, with their own
 *   state_id/lga_id kept equal to the report's (composite FK, cascading on
 *   update), never classified below the report, and locked with it once
 *   reviewed (triggers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coverage_reports', function (Blueprint $table) {
            $table->string('title', 200)->nullable();
            $table->date('report_date')->nullable();
        });

        DB::statement('ALTER TABLE coverage_reports DISABLE TRIGGER coverage_reports_locked');
        DB::statement('UPDATE coverage_reports SET report_date = COALESCE(submitted_at::date, created_at::date, CURRENT_DATE)');
        DB::statement('ALTER TABLE coverage_reports ENABLE TRIGGER coverage_reports_locked');
        DB::statement('ALTER TABLE coverage_reports ALTER COLUMN report_date SET DEFAULT CURRENT_DATE');
        DB::statement('ALTER TABLE coverage_reports ALTER COLUMN report_date SET NOT NULL');

        // Anything past draft has been submitted with its text; the rating is optional.
        DB::statement('ALTER TABLE coverage_reports DROP CONSTRAINT coverage_reports_submitted_complete');
        DB::statement("ALTER TABLE coverage_reports ADD CONSTRAINT coverage_reports_submitted_complete CHECK (status = 'draft' OR (submitted_at IS NOT NULL AND summary IS NOT NULL))");

        Schema::create('coverage_report_attachments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('coverage_report_id')->index();
            $table->foreignId('state_id')->index();
            $table->foreignId('lga_id')->index();
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
            $table->spatialIndex('captured_location');
            // Same place as the report, kept in sync (access rule 9).
            $table->foreign(['coverage_report_id', 'lga_id', 'state_id'], 'coverage_attachments_report_place_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('coverage_reports')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign(['lga_id', 'state_id'], 'coverage_attachments_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE coverage_report_attachments ADD CONSTRAINT coverage_attachments_media_type_valid CHECK (media_type IN ('image', 'video'))");
        DB::statement("ALTER TABLE coverage_report_attachments ADD CONSTRAINT coverage_attachments_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement("ALTER TABLE coverage_report_attachments ADD CONSTRAINT coverage_attachments_scan_status_valid CHECK (scan_status IN ('clean', 'quarantined', 'infected'))");
        DB::statement("ALTER TABLE coverage_report_attachments ADD CONSTRAINT coverage_attachments_sha256_hex CHECK (sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE coverage_report_attachments ADD CONSTRAINT coverage_attachments_size_positive CHECK (size_bytes > 0)');
        DB::statement("ALTER TABLE coverage_report_attachments ADD CONSTRAINT coverage_attachments_exif_object CHECK (exif_summary IS NULL OR jsonb_typeof(exif_summary) = 'object')");

        // Never classified below its report...
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_coverage_attachment_not_below_report() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF buffalo_classification_rank(NEW.classification) < (
                    SELECT buffalo_classification_rank(classification) FROM coverage_reports WHERE id = NEW.coverage_report_id
                ) THEN
                    RAISE EXCEPTION 'An attachment cannot be classified below its report.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        DB::statement('CREATE TRIGGER coverage_attachments_not_below_report BEFORE INSERT OR UPDATE OF classification, coverage_report_id ON coverage_report_attachments FOR EACH ROW EXECUTE FUNCTION buffalo_coverage_attachment_not_below_report()');

        // ...and a report is not raised above its attachments: they are raised first.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_coverage_report_not_above_attachments() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM coverage_report_attachments
                    WHERE coverage_report_id = NEW.id
                      AND buffalo_classification_rank(classification) < buffalo_classification_rank(NEW.classification)
                ) THEN
                    RAISE EXCEPTION 'Raise the report''s attachments before raising the report.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        DB::statement('CREATE TRIGGER coverage_reports_not_above_attachments BEFORE UPDATE OF classification ON coverage_reports FOR EACH ROW EXECUTE FUNCTION buffalo_coverage_report_not_above_attachments()');

        // Locked with a reviewed report: no new attachments, no removal, no
        // caption or classification change. The malware scan and integrity
        // processing of a file uploaded before the review may still finish.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_coverage_attachment_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                report_status text;
            BEGIN
                SELECT status INTO report_status FROM coverage_reports
                    WHERE id = COALESCE(NEW.coverage_report_id, OLD.coverage_report_id);

                IF report_status = 'reviewed' AND (
                    TG_OP <> 'UPDATE'
                    OR NEW.caption IS DISTINCT FROM OLD.caption
                    OR NEW.classification IS DISTINCT FROM OLD.classification
                    OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
                ) THEN
                    RAISE EXCEPTION 'a reviewed security report is locked' USING ERRCODE = 'check_violation';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END $$
            SQL);
        DB::statement('CREATE TRIGGER coverage_attachments_locked BEFORE INSERT OR UPDATE OR DELETE ON coverage_report_attachments FOR EACH ROW EXECUTE FUNCTION buffalo_coverage_attachment_locked()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS coverage_reports_not_above_attachments ON coverage_reports');
        Schema::dropIfExists('coverage_report_attachments');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_coverage_attachment_locked()');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_coverage_report_not_above_attachments()');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_coverage_attachment_not_below_report()');

        DB::statement('ALTER TABLE coverage_reports DROP CONSTRAINT coverage_reports_submitted_complete');
        DB::statement("ALTER TABLE coverage_reports ADD CONSTRAINT coverage_reports_submitted_complete CHECK (status = 'draft' OR (submitted_at IS NOT NULL AND overall_rating IS NOT NULL AND summary IS NOT NULL))");

        Schema::table('coverage_reports', function (Blueprint $table) {
            $table->dropColumn(['title', 'report_date']);
        });
    }
};
