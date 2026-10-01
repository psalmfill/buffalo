<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Incident lists, map and dashboards (Phase 5D).
 *
 * - Indexes for the default list (state, status, time), the LGA and type
 *   breakdowns, and the casualty sums. `location` already has a GIST index
 *   (5A).
 * - `incident_saved_filters`: a user's named filter sets (their own only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->index(['state_id', 'status', 'occurred_at'], 'incidents_state_status_occurred_index');
            $table->index(['lga_id', 'occurred_at'], 'incidents_lga_occurred_index');
            $table->index(['incident_type_id', 'occurred_at'], 'incidents_type_occurred_index');
            $table->index(['occurred_at', 'id'], 'incidents_occurred_id_index');
            $table->index(['reporting_officer_id', 'status'], 'incidents_officer_status_index');
        });

        // Live (not removed) casualty rows per incident, for summaries.
        DB::statement('CREATE INDEX incident_casualties_live_incident_index ON incident_casualties (incident_id, outcome) WHERE deleted_at IS NULL');

        Schema::create('incident_saved_filters', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->jsonb('filters');
            $table->timestampsTz();

            $table->unique(['user_id', 'name']);
        });
        DB::statement("ALTER TABLE incident_saved_filters ADD CONSTRAINT incident_saved_filters_object CHECK (jsonb_typeof(filters) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_saved_filters');
        DB::statement('DROP INDEX IF EXISTS incident_casualties_live_incident_index');

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex('incidents_state_status_occurred_index');
            $table->dropIndex('incidents_lga_occurred_index');
            $table->dropIndex('incidents_type_occurred_index');
            $table->dropIndex('incidents_occurred_id_index');
            $table->dropIndex('incidents_officer_status_index');
        });
    }
};
