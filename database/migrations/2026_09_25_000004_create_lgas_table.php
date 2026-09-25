<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference data: deactivated (is_active = false), never deleted.
 * `code` is three uppercase letters, unique within the state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lgas', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('senatorial_district_id')->index()->constrained()->restrictOnDelete();
            $table->string('code', 10);
            $table->string('name');
            $table->string('headquarters')->nullable();
            $table->decimal('area_km2', 12, 2)->nullable();
            $table->integer('population_estimate')->nullable();
            $table->geography('centroid', subtype: 'point', srid: 4326)->nullable();
            $table->geography('boundary', subtype: 'multipolygon', srid: 4326)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['state_id', 'code'], 'lgas_state_id_code_unique');
            // Deferrable so the hierarchy import can swap names between two
            // LGAs inside one transaction; checked immediately otherwise.
            $table->unique(['state_id', 'name'], 'lgas_state_id_name_unique')
                ->deferrable()
                ->initiallyImmediate();

            // Target of the composite foreign key on wards.
            $table->unique(['id', 'state_id'], 'lgas_id_state_id_unique');

            // An LGA's senatorial district must belong to the same state: the
            // (district, state) pair must exist in senatorial_districts. Also
            // blocks moving a district to another state while LGAs use it.
            $table->foreign(['senatorial_district_id', 'state_id'], 'lgas_district_same_state_foreign')
                ->references(['id', 'state_id'])
                ->on('senatorial_districts')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->spatialIndex('centroid');
            $table->spatialIndex('boundary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lgas');
    }
};
