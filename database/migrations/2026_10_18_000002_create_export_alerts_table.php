<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unusual export volume alerts already sent (Phase 8B.3): once per user,
 * rule and day (Africa/Lagos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('rule', 40);
            $table->date('day');
            $table->timestampTz('created_at');
            $table->unique(['user_id', 'rule', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_alerts');
    }
};
