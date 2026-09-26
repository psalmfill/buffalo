<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blind indexes for encrypted fields (spatie/laravel-ciphersweet, Phase 3A):
 * keyed hashes that allow exact-match search without decrypting. One row
 * per record and index name (e.g. nin_bidx).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blind_indexes', function (Blueprint $table) {
            $table->morphs('indexable');
            $table->string('name');
            $table->string('value');

            $table->index(['name', 'value']);
            $table->unique(['indexable_type', 'indexable_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blind_indexes');
    }
};
