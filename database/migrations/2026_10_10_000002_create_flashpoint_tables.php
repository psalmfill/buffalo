<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flashpoints and blackspots (Phase 6A).
 *
 * - `flashpoints`: an area (point + radius) of recurring tension
 *   (flashpoint) or recurring incidents (blackspot). Jurisdiction-scoped
 *   (composite FK) and classified; the point must lie inside its LGA's
 *   boundary (trigger). The current concern, status, recommended action
 *   and review date change only through an assessment.
 * - `flashpoint_assessments`: append-only (trigger); the place follows the
 *   flashpoint (composite FK, ON UPDATE CASCADE).
 * - `flashpoint_incidents`, `flashpoint_security_notes`: links, on the
 *   flashpoint's place (cascading composite FK). Unlinking soft-deletes
 *   the link (logged); one active link per pair.
 * - `flashpoint_incident_alerts`: "incident near a flashpoint" notices
 *   already sent, once per flashpoint and incident.
 */
return new class extends Migration
{
    private const LINKS = ['flashpoint_incidents' => 'incident_id', 'flashpoint_security_notes' => 'community_security_note_id'];

    public function up(): void
    {
        Schema::create('flashpoints', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('kind', 16);
            $table->string('name', 200);
            $table->foreignId('threat_type_id')->index()->constrained()->restrictOnDelete();
            $table->string('concern_level', 16);
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('community_id')->nullable()->index();
            $table->geography('location', subtype: 'point', srid: 4326);
            $table->integer('radius_meters')->default(1000);
            $table->text('description');
            $table->text('recommended_action')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('classification', 16)->default('restricted');
            $table->timestampTz('last_assessed_at');
            $table->foreignId('last_assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('review_due_at');
            $table->date('review_reminded_for')->nullable();
            $table->timestampTz('escalation_notified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['lga_id', 'state_id'], 'flashpoints_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['community_id', 'lga_id'], 'flashpoints_community_same_lga_foreign')
                ->references(['id', 'lga_id'])->on('communities')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id', 'lga_id', 'state_id'], 'flashpoints_id_lga_state_unique');
            $table->spatialIndex('location');
            $table->index(['status', 'review_due_at']);
        });

        DB::statement("ALTER TABLE flashpoints ADD CONSTRAINT flashpoints_kind_valid CHECK (kind IN ('flashpoint', 'blackspot'))");
        DB::statement("ALTER TABLE flashpoints ADD CONSTRAINT flashpoints_concern_valid CHECK (concern_level IN ('low', 'moderate', 'high', 'severe'))");
        DB::statement("ALTER TABLE flashpoints ADD CONSTRAINT flashpoints_status_valid CHECK (status IN ('active', 'monitoring', 'dormant', 'resolved'))");
        DB::statement("ALTER TABLE flashpoints ADD CONSTRAINT flashpoints_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement('ALTER TABLE flashpoints ADD CONSTRAINT flashpoints_radius_range CHECK (radius_meters BETWEEN 100 AND 50000)');
        DB::statement("ALTER TABLE flashpoints ADD CONSTRAINT flashpoints_text_present CHECK (btrim(name) <> '' AND btrim(description) <> '')");
        DB::statement('ALTER TABLE flashpoints ADD COLUMN search_name text GENERATED ALWAYS AS (buffalo_search_text(name)) STORED');
        DB::statement('CREATE INDEX flashpoints_search_name_trgm ON flashpoints USING gin (search_name gin_trgm_ops)');
        DB::statement('CREATE TRIGGER flashpoints_location_in_lga BEFORE INSERT OR UPDATE OF location, lga_id ON flashpoints FOR EACH ROW EXECUTE FUNCTION buffalo_community_location_in_lga()');

        Schema::create('flashpoint_assessments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('flashpoint_id')->index();
            $table->foreignId('state_id')->index();
            $table->foreignId('lga_id')->index();
            $table->string('concern_level', 16);
            $table->string('status', 16);
            $table->text('assessment');
            $table->text('recommended_action')->nullable();
            $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('assessed_at');

            $table->foreign(['flashpoint_id', 'lga_id', 'state_id'], 'flashpoint_assessments_place_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('flashpoints')->restrictOnDelete()->cascadeOnUpdate();
            $table->index(['flashpoint_id', 'assessed_at']);
        });

        DB::statement("ALTER TABLE flashpoint_assessments ADD CONSTRAINT flashpoint_assessments_concern_valid CHECK (concern_level IN ('low', 'moderate', 'high', 'severe'))");
        DB::statement("ALTER TABLE flashpoint_assessments ADD CONSTRAINT flashpoint_assessments_status_valid CHECK (status IN ('active', 'monitoring', 'dormant', 'resolved'))");
        DB::statement("ALTER TABLE flashpoint_assessments ADD CONSTRAINT flashpoint_assessments_text_present CHECK (btrim(assessment) <> '')");
        DB::statement('CREATE TRIGGER flashpoint_assessments_append_only BEFORE UPDATE OR DELETE ON flashpoint_assessments FOR EACH ROW EXECUTE FUNCTION buffalo_append_only_follow_place()');
        DB::statement('CREATE TRIGGER flashpoint_assessments_no_truncate BEFORE TRUNCATE ON flashpoint_assessments FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_audit_change()');

        foreach (self::LINKS as $name => $column) {
            Schema::create($name, function (Blueprint $table) use ($name, $column) {
                $table->id();
                $table->ulid('ulid')->unique();
                $table->foreignId('flashpoint_id')->index();
                $table->foreignId('state_id')->index();
                $table->foreignId('lga_id')->index();
                $table->foreignId($column)->index()->constrained()->restrictOnDelete();
                $table->text('link_note')->nullable();
                $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('linked_at');
                $table->foreignId('unlinked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletesTz();

                $table->foreign(['flashpoint_id', 'lga_id', 'state_id'], "{$name}_place_foreign")
                    ->references(['id', 'lga_id', 'state_id'])->on('flashpoints')->restrictOnDelete()->cascadeOnUpdate();
            });

            DB::statement("CREATE UNIQUE INDEX {$name}_active_unique ON {$name} (flashpoint_id, {$column}) WHERE deleted_at IS NULL");
        }

        Schema::create('flashpoint_incident_alerts', function (Blueprint $table) {
            $table->foreignId('flashpoint_id')->constrained()->restrictOnDelete();
            $table->foreignId('incident_id')->constrained()->restrictOnDelete();
            $table->timestampTz('alerted_at');
            $table->primary(['flashpoint_id', 'incident_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flashpoint_incident_alerts');

        foreach (array_keys(self::LINKS) as $name) {
            Schema::dropIfExists($name);
        }

        Schema::dropIfExists('flashpoint_assessments');
        Schema::dropIfExists('flashpoints');
    }
};
