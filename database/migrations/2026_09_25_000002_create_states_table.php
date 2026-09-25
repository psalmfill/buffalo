<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference data: deactivated (is_active = false), never deleted.
 * `code` is the ISO 3166-2:NG suffix (e.g. LA), used in reference numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('geopolitical_zone_id')->index()->constrained()->restrictOnDelete();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('capital')->nullable();
            $table->geography('centroid', subtype: 'point', srid: 4326)->nullable();
            $table->geography('boundary', subtype: 'multipolygon', srid: 4326)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->spatialIndex('centroid');
            $table->spatialIndex('boundary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('states');
    }
};
