<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links from Phase 2 records to persons (Phase 3A): a community's
 * traditional ruler and a religious institution's leader. The person must
 * hold the matching role (checked by the form requests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->foreignId('traditional_ruler_id')->nullable()->index()->constrained('persons')->restrictOnDelete();
        });

        Schema::table('religious_institutions', function (Blueprint $table) {
            $table->foreignId('leader_person_id')->nullable()->index()->constrained('persons')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('religious_institutions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leader_person_id');
        });

        Schema::table('communities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('traditional_ruler_id');
        });
    }
};
