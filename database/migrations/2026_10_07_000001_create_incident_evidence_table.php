<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Incident evidence (Phase 5B).
 *
 * - The item carries its own state_id/lga_id, bound to the incident's by a
 *   composite FK with ON UPDATE CASCADE (access rule 9), like the 5A
 *   related tables.
 * - The file itself is a medialibrary media row (collection `original`) on
 *   the private `evidence` disk, stored exactly as uploaded. `sha256` is
 *   the hash of those bytes, computed at upload.
 * - Classification is never below the incident's: an item cannot be saved
 *   below it, and an incident cannot be raised above an item still below
 *   the new level (the application raises the items first, logged).
 * - `processed_at` is null while a large upload is verified and processed
 *   on the queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_classification_rank(level text) RETURNS integer
            LANGUAGE sql IMMUTABLE AS $$
                SELECT CASE level WHEN 'restricted' THEN 1 WHEN 'confidential' THEN 2 WHEN 'secret' THEN 3 END
            $$
            SQL);

        Schema::create('incident_evidence', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('incident_id')->index();
            $table->foreignId('state_id')->index();
            $table->foreignId('lga_id')->index();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('evidence_type', 20);
            $table->string('original_filename', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestampTz('captured_at')->nullable();
            $table->geography('captured_location', subtype: 'point', srid: 4326)->nullable();
            $table->jsonb('exif_summary')->nullable();
            $table->string('classification', 20)->default('restricted');
            $table->timestampTz('processed_at')->nullable();
            $table->foreignId('uploaded_by')->index()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->spatialIndex('captured_location');
            $table->foreign(['incident_id', 'lga_id', 'state_id'], 'incident_evidence_incident_place_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('incidents')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign(['lga_id', 'state_id'], 'incident_evidence_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE incident_evidence ADD CONSTRAINT incident_evidence_type_valid CHECK (evidence_type IN ('image', 'document', 'video', 'audio'))");
        DB::statement("ALTER TABLE incident_evidence ADD CONSTRAINT incident_evidence_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement("ALTER TABLE incident_evidence ADD CONSTRAINT incident_evidence_sha256_hex CHECK (sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE incident_evidence ADD CONSTRAINT incident_evidence_size_positive CHECK (size_bytes > 0)');
        DB::statement("ALTER TABLE incident_evidence ADD CONSTRAINT incident_evidence_exif_object CHECK (exif_summary IS NULL OR jsonb_typeof(exif_summary) = 'object')");

        // An item is never classified below its incident.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_evidence_not_below_incident() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF buffalo_classification_rank(NEW.classification) < (
                    SELECT buffalo_classification_rank(classification) FROM incidents WHERE id = NEW.incident_id
                ) THEN
                    RAISE EXCEPTION 'Evidence cannot be classified below its incident.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        DB::statement('CREATE TRIGGER incident_evidence_not_below_incident BEFORE INSERT OR UPDATE OF classification, incident_id ON incident_evidence FOR EACH ROW EXECUTE FUNCTION buffalo_evidence_not_below_incident()');

        // ...and an incident is not raised above its items: they are raised first.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_incident_not_above_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM incident_evidence
                    WHERE incident_id = NEW.id
                      AND buffalo_classification_rank(classification) < buffalo_classification_rank(NEW.classification)
                ) THEN
                    RAISE EXCEPTION 'Raise the incident''s evidence before raising the incident.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        DB::statement('CREATE TRIGGER incidents_not_above_evidence BEFORE UPDATE OF classification ON incidents FOR EACH ROW EXECUTE FUNCTION buffalo_incident_not_above_evidence()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS incidents_not_above_evidence ON incidents');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_incident_not_above_evidence()');
        Schema::dropIfExists('incident_evidence');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_evidence_not_below_incident()');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_classification_rank(text)');
    }
};
