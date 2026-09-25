<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference data: deactivated (is_active = false), never deleted.
 * `state_id` is denormalised from the LGA (CLAUDE.md: records store state_id
 * and lga_id directly) and kept consistent by a composite foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wards', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['lga_id', 'code'], 'wards_lga_id_code_unique');

            // A ward's state_id must equal its LGA's state_id.
            $table->foreign(['lga_id', 'state_id'], 'wards_lga_same_state_foreign')
                ->references(['id', 'state_id'])
                ->on('lgas')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wards');
    }
};
