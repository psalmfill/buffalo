<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8C: operational alerts (backups, disks, queues, services). One open
 * row per condition: super admins are notified when it is raised and when
 * it clears, never repeatedly while it lasts. Rows hold no intelligence
 * content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100);
            $table->string('severity', 16);
            $table->string('title', 200);
            $table->text('detail');
            $table->timestampTz('raised_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('cleared_at')->nullable();
            $table->index(['cleared_at', 'raised_at']);
        });

        DB::statement('CREATE UNIQUE INDEX system_alerts_one_open ON system_alerts (key) WHERE cleared_at IS NULL');
        DB::statement("ALTER TABLE system_alerts ADD CONSTRAINT system_alerts_severity CHECK (severity IN ('warning', 'critical'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
    }
};
