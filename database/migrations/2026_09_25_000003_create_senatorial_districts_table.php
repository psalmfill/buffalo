<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference data: deactivated (is_active = false), never deleted.
 * `code` is globally unique, format <STATE>-<X> (e.g. LA-C).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('senatorial_districts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Target of the composite foreign key on lgas that guarantees an
            // LGA's district belongs to the LGA's own state.
            $table->unique(['id', 'state_id'], 'senatorial_districts_id_state_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('senatorial_districts');
    }
};
