<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * List exports and printable records (Phase 11E).
 *
 * - report_runs.report_type gains `list` (any registered list exporter) and
 *   the single-record PDFs: flashpoint, organization site, arrest, threat
 *   report, criminal group and person.
 * - `is_sensitive` and `reason`: exports of the sensitive lists (persons,
 *   persons of interest, criminal group members, organization contacts)
 *   always carry the requester's stated reason, at least 20 characters.
 */
return new class extends Migration
{
    private const TYPES = ['sitrep', 'weekly', 'monthly', 'incident_list', 'incident', 'lga_profile', 'coverage', 'analytics'];

    private const NEW_TYPES = ['list', 'flashpoint', 'organization_site', 'arrest', 'threat_report', 'criminal_group', 'person'];

    public function up(): void
    {
        Schema::table('report_runs', function (Blueprint $table) {
            $table->boolean('is_sensitive')->default(false);
            $table->text('reason')->nullable();
        });

        DB::statement('ALTER TABLE report_runs DROP CONSTRAINT report_runs_type');
        DB::statement('ALTER TABLE report_runs ADD CONSTRAINT report_runs_type CHECK (report_type IN ('.self::quoted([...self::TYPES, ...self::NEW_TYPES]).'))');
        DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_sensitive_reason CHECK (NOT is_sensitive OR (report_type = 'list' AND reason IS NOT NULL AND char_length(btrim(reason)) >= 20))");
        DB::statement('CREATE INDEX report_runs_sensitive_requester ON report_runs (requested_by, created_at) WHERE is_sensitive');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS report_runs_sensitive_requester');
        DB::statement('ALTER TABLE report_runs DROP CONSTRAINT report_runs_sensitive_reason');
        DB::statement('ALTER TABLE report_runs DROP CONSTRAINT report_runs_type');
        DB::statement('ALTER TABLE report_runs ADD CONSTRAINT report_runs_type CHECK (report_type IN ('.self::quoted(self::TYPES).'))');

        Schema::table('report_runs', function (Blueprint $table) {
            $table->dropColumn(['is_sensitive', 'reason']);
        });
    }

    /** @param list<string> $values */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => "'{$v}'", $values));
    }
};
