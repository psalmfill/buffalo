<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Boundaries (Phase 6A.0).
 *
 * - `boundary_sources`: where the LGA or state boundaries came from, with
 *   the attribution shown on maps. One row per import.
 * - `boundary_simplified`: display geometries, simplified once after each
 *   import at three tolerances (national, state and LGA zoom), so maps
 *   never ship full-resolution boundaries. The authoritative geometry stays
 *   in `lgas.boundary` / `states.boundary`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boundary_sources', function (Blueprint $table) {
            $table->id();
            $table->string('level', 10);
            $table->string('source_name', 200);
            $table->text('attribution');
            $table->boolean('synthetic')->default(false);
            $table->unsignedInteger('feature_count');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('imported_at');
        });
        DB::statement("ALTER TABLE boundary_sources ADD CONSTRAINT boundary_sources_level_valid CHECK (level IN ('lga', 'state'))");

        Schema::create('boundary_simplified', function (Blueprint $table) {
            $table->id();
            $table->string('place_type', 10);
            $table->unsignedBigInteger('place_id');
            $table->string('zoom', 10);
            $table->geometry('geom', subtype: 'multipolygon', srid: 4326);

            $table->unique(['place_type', 'place_id', 'zoom']);
            $table->spatialIndex('geom');
        });
        DB::statement("ALTER TABLE boundary_simplified ADD CONSTRAINT boundary_simplified_type_valid CHECK (place_type IN ('lga', 'state'))");
        DB::statement("ALTER TABLE boundary_simplified ADD CONSTRAINT boundary_simplified_zoom_valid CHECK (zoom IN ('national', 'state', 'lga'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('boundary_simplified');
        Schema::dropIfExists('boundary_sources');
    }
};
