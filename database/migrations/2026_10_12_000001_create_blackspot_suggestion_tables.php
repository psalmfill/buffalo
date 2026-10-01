<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blackspot suggestions (Phase 6C): clusters of incidents found by the
 * nightly DBSCAN job, not yet covered by a flashpoint area.
 *
 * - `blackspot_suggestions`: scoped by the place of the cluster's centre
 *   (composite FK). Machine-generated: open suggestions are replaced on the
 *   next run; accepted and dismissed ones are kept as the record.
 * - `blackspot_suggestion_incidents`: member incident ids. Pages list and
 *   count only the members the viewer can see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blackspot_suggestions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->geography('centroid', subtype: 'point', srid: 4326);
            $table->integer('radius_meters');
            $table->integer('incident_count');
            $table->foreignId('dominant_incident_type_id')->nullable()->constrained('incident_types')->restrictOnDelete();
            $table->string('category', 32)->nullable();
            $table->timestampTz('first_occurred_at');
            $table->timestampTz('last_occurred_at');
            $table->string('status', 16)->default('open');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('dismiss_reason')->nullable();
            $table->foreignId('flashpoint_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('generated_at');

            $table->foreign(['lga_id', 'state_id'], 'blackspot_suggestions_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->spatialIndex('centroid');
            $table->index(['state_id', 'status']);
        });

        DB::statement("ALTER TABLE blackspot_suggestions ADD CONSTRAINT blackspot_suggestions_status_valid CHECK (status IN ('open', 'accepted', 'dismissed'))");
        DB::statement("ALTER TABLE blackspot_suggestions ADD CONSTRAINT blackspot_suggestions_dismiss_reason CHECK (status <> 'dismissed' OR (dismiss_reason IS NOT NULL AND btrim(dismiss_reason) <> ''))");
        DB::statement('ALTER TABLE blackspot_suggestions ADD CONSTRAINT blackspot_suggestions_radius CHECK (radius_meters BETWEEN 100 AND 50000)');

        Schema::create('blackspot_suggestion_incidents', function (Blueprint $table) {
            $table->foreignId('blackspot_suggestion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->index()->constrained()->restrictOnDelete();
            $table->primary(['blackspot_suggestion_id', 'incident_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blackspot_suggestion_incidents');
        Schema::dropIfExists('blackspot_suggestions');
    }
};
