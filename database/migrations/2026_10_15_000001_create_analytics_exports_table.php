<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics chart exports (Phase 7C): one row per PNG or CSV download, the
 * subject of its record_access_logs entry (action `export`, context
 * `analytics`). Holds the chart and the filters, never figures. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_exports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('area', 32);
            $table->string('chart', 64);
            $table->string('format', 8);
            $table->jsonb('filters');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE analytics_exports ADD CONSTRAINT analytics_exports_format CHECK (format IN ('png', 'csv'))");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER analytics_exports_immutable
                BEFORE UPDATE OR DELETE ON analytics_exports
                FOR EACH ROW EXECUTE FUNCTION buffalo_reject_audit_change();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_exports');
    }
};
