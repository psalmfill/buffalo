<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Threat reports (Phase 6B): early warnings of what may be about to happen.
 *
 * - `threat_reports`: jurisdiction-scoped (composite FKs) and classified.
 *   Reference THR/<STATE>/<YEAR>/<5 digits>, gap-free per state and year
 *   (`threat_report_counters`, same approach as incidents). The optional
 *   point must lie inside its LGA (trigger); a radius needs a point.
 *   Leaving `active` needs a status note (CHECK).
 * - Link tables (flashpoints, criminal groups, incidents) sit on the threat
 *   report's place (cascading composite FK); unlinking soft-deletes the
 *   link; one active link per pair.
 * - `threat_report_incident_matches`: threat reports found when an incident
 *   was submitted ("possibly related"), recorded once per pair.
 */
return new class extends Migration
{
    private const LINKS = [
        'threat_report_flashpoints' => 'flashpoint_id',
        'threat_report_criminal_groups' => 'criminal_group_id',
        'threat_report_incidents' => 'incident_id',
    ];

    public function up(): void
    {
        Schema::create('threat_report_counters', function (Blueprint $table) {
            $table->foreignId('state_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_value');
            $table->primary(['state_id', 'year']);
        });

        Schema::create('threat_reports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('reference_no', 24)->unique();
            $table->foreignId('threat_type_id')->index()->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description');
            $table->string('credibility', 16);
            $table->string('urgency', 16);
            $table->string('source_type', 24);
            $table->text('source_notes')->nullable();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('community_id')->nullable()->index();
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->integer('radius_meters')->nullable();
            $table->text('target_description')->nullable();
            $table->timestampTz('expected_from')->nullable();
            $table->timestampTz('expected_until')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('expiry_warned_for')->nullable();
            $table->string('status', 16)->default('active');
            $table->text('status_note')->nullable();
            $table->text('recommended_action')->nullable();
            $table->string('classification', 16)->default('restricted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['lga_id', 'state_id'], 'threat_reports_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['community_id', 'lga_id'], 'threat_reports_community_same_lga_foreign')
                ->references(['id', 'lga_id'])->on('communities')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id', 'lga_id', 'state_id'], 'threat_reports_id_lga_state_unique');
            $table->spatialIndex('location');
            $table->index(['status', 'expires_at']);
            $table->index(['status', 'urgency']);
        });

        DB::statement("ALTER TABLE threat_reports ADD CONSTRAINT threat_reports_credibility_valid CHECK (credibility IN ('unconfirmed', 'possible', 'probable', 'confirmed'))");
        DB::statement("ALTER TABLE threat_reports ADD CONSTRAINT threat_reports_urgency_valid CHECK (urgency IN ('routine', 'priority', 'immediate'))");
        DB::statement("ALTER TABLE threat_reports ADD CONSTRAINT threat_reports_source_valid CHECK (source_type IN ('informant', 'community', 'agency_report', 'open_source', 'officer_observation', 'other'))");
        DB::statement("ALTER TABLE threat_reports ADD CONSTRAINT threat_reports_status_valid CHECK (status IN ('active', 'mitigated', 'materialised', 'expired', 'withdrawn'))");
        DB::statement("ALTER TABLE threat_reports ADD CONSTRAINT threat_reports_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement('ALTER TABLE threat_reports ADD CONSTRAINT threat_reports_radius_range CHECK (radius_meters IS NULL OR (radius_meters BETWEEN 100 AND 50000 AND location IS NOT NULL))');
        DB::statement('ALTER TABLE threat_reports ADD CONSTRAINT threat_reports_window_order CHECK (expected_until IS NULL OR expected_from IS NULL OR expected_until >= expected_from)');
        DB::statement("ALTER TABLE threat_reports ADD CONSTRAINT threat_reports_text_present CHECK (btrim(title) <> '' AND btrim(description) <> '')");
        DB::statement("ALTER TABLE threat_reports ADD CONSTRAINT threat_reports_status_note CHECK (status = 'active' OR (status_note IS NOT NULL AND btrim(status_note) <> ''))");
        DB::statement('CREATE TRIGGER threat_reports_location_in_lga BEFORE INSERT OR UPDATE OF location, lga_id ON threat_reports FOR EACH ROW EXECUTE FUNCTION buffalo_community_location_in_lga()');

        foreach (self::LINKS as $name => $column) {
            Schema::create($name, function (Blueprint $table) use ($name, $column) {
                $table->id();
                $table->ulid('ulid')->unique();
                $table->foreignId('threat_report_id')->index();
                $table->foreignId('state_id')->index();
                $table->foreignId('lga_id')->index();
                $table->foreignId($column)->index()->constrained()->restrictOnDelete();

                if ($column === 'criminal_group_id') {
                    $table->string('role', 20);
                }

                if ($column === 'incident_id') {
                    $table->string('relation', 20);
                }

                $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('linked_at');
                $table->foreignId('unlinked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletesTz();

                $table->foreign(['threat_report_id', 'lga_id', 'state_id'], "{$name}_place_foreign")
                    ->references(['id', 'lga_id', 'state_id'])->on('threat_reports')->restrictOnDelete()->cascadeOnUpdate();
            });

            DB::statement("CREATE UNIQUE INDEX {$name}_active_unique ON {$name} (threat_report_id, {$column}) WHERE deleted_at IS NULL");
        }

        DB::statement("ALTER TABLE threat_report_criminal_groups ADD CONSTRAINT threat_report_criminal_groups_role_valid CHECK (role IN ('suspected', 'named_in_report'))");
        DB::statement("ALTER TABLE threat_report_incidents ADD CONSTRAINT threat_report_incidents_relation_valid CHECK (relation IN ('materialised_as', 'related'))");

        Schema::create('threat_report_incident_matches', function (Blueprint $table) {
            $table->foreignId('threat_report_id')->constrained()->restrictOnDelete();
            $table->foreignId('incident_id')->index()->constrained()->restrictOnDelete();
            $table->timestampTz('matched_at');
            $table->primary(['threat_report_id', 'incident_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threat_report_incident_matches');

        foreach (array_keys(self::LINKS) as $name) {
            Schema::dropIfExists($name);
        }

        Schema::dropIfExists('threat_reports');
        Schema::dropIfExists('threat_report_counters');
    }
};
