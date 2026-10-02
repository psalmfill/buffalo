<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports (Phase 7D).
 *
 * - `report_schedules`: a user's recurring SITREP, weekly or monthly
 *   report, generated as its owner at Africa/Lagos times.
 * - `report_runs`: one generation. Built on the queue through the
 *   requester's scopes; the file lives on the private `reports` disk and
 *   expires; the run record stays. Only the requester may download it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->string('report_type', 24);
            $table->jsonb('parameters');
            $table->string('format', 8);
            $table->string('cadence', 8);
            $table->string('time_of_day', 5);
            $table->smallInteger('weekday')->nullable();
            $table->smallInteger('day_of_month')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('next_run_at');
            $table->timestampsTz();
            $table->index(['is_active', 'next_run_at']);
        });

        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_type CHECK (report_type IN ('sitrep', 'weekly', 'monthly'))");
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_cadence CHECK (cadence IN ('daily', 'weekly', 'monthly'))");
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_time CHECK (time_of_day ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$')");
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_weekday CHECK ((cadence = 'weekly') = (weekday IS NOT NULL) AND (weekday IS NULL OR weekday BETWEEN 1 AND 7))");
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_day CHECK ((cadence = 'monthly') = (day_of_month IS NOT NULL) AND (day_of_month IS NULL OR day_of_month BETWEEN 1 AND 28))");

        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('report_type', 24);
            $table->string('format', 8);
            $table->jsonb('parameters');
            $table->foreignId('requested_by')->index()->constrained('users')->restrictOnDelete();
            $table->foreignId('report_schedule_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('queued');
            $table->string('classification', 16)->default('restricted');
            $table->string('file_path')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->integer('row_count')->nullable();
            $table->integer('page_count')->nullable();
            $table->string('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'expires_at']);
        });

        DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_type CHECK (report_type IN ('sitrep', 'weekly', 'monthly', 'incident_list', 'incident', 'lga_profile', 'coverage', 'analytics'))");
        DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_format CHECK (format IN ('pdf', 'xlsx', 'csv'))");
        DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_status CHECK (status IN ('queued', 'running', 'completed', 'failed', 'expired'))");
        DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_classification CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_completed_file CHECK (status <> 'completed' OR (file_path IS NOT NULL AND sha256 IS NOT NULL AND expires_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('report_schedules');
    }
};
