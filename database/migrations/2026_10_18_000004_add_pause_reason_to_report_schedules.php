<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8B.5: why a report schedule is paused. A posting change (role or
 * jurisdiction) and deactivation pause all of a user's schedules; the
 * owner reviews and resumes them with one click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_schedules', function (Blueprint $table): void {
            $table->string('paused_reason', 24)->nullable();
            $table->timestampTz('paused_at')->nullable();
        });

        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_paused_reason CHECK (paused_reason IN ('manual', 'posting_change', 'deactivated', 'owner_blocked'))");
        DB::statement("UPDATE report_schedules SET paused_reason = 'manual', paused_at = updated_at WHERE is_active = false");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE report_schedules DROP CONSTRAINT report_schedules_paused_reason');

        Schema::table('report_schedules', function (Blueprint $table): void {
            $table->dropColumn(['paused_reason', 'paused_at']);
        });
    }
};
