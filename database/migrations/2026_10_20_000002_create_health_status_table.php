<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8C.4: the latest result of things System health reports and the
 * monitor checks (each scheduled command's last run, the last audit chain
 * verification). One row per key, overwritten; no intelligence content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_status', function (Blueprint $table) {
            $table->string('key', 150)->primary();
            $table->boolean('ok')->nullable();
            $table->text('detail')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestampTz('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_status');
    }
};
