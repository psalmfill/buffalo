<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * State profiles (Phase 11F): a state's reference profile, one per state,
 * created on first edit. Mirrors lga_profiles (Phase 2B), with the rich
 * text fields and their plain text copies of Phase 11B from the start.
 * Jurisdiction-scoped by state_id only (state-level records).
 *
 * report_runs.report_type gains `state_profile` (the state profile PDF).
 */
return new class extends Migration
{
    private const RICH = ['history', 'economic_activities', 'major_roads', 'waterways', 'borders', 'general_notes'];

    private const TYPES = ['sitrep', 'weekly', 'monthly', 'incident_list', 'incident', 'lga_profile', 'coverage', 'analytics', 'list', 'flashpoint', 'organization_site', 'arrest', 'threat_report', 'criminal_group', 'person'];

    public function up(): void
    {
        Schema::create('state_profiles', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->unique()->constrained()->restrictOnDelete();
            foreach (self::RICH as $field) {
                $table->text($field)->nullable();
                $table->text("{$field}_text")->nullable();
            }
            $table->jsonb('terrain')->default(DB::raw("'[]'::jsonb"));
            $table->timestampTz('last_reviewed_at')->nullable();
            $table->foreignId('last_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("ALTER TABLE state_profiles ADD CONSTRAINT state_profiles_terrain_array CHECK (jsonb_typeof(terrain) = 'array')");

        DB::statement('ALTER TABLE report_runs DROP CONSTRAINT report_runs_type');
        DB::statement('ALTER TABLE report_runs ADD CONSTRAINT report_runs_type CHECK (report_type IN ('.self::quoted([...self::TYPES, 'state_profile']).'))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE report_runs DROP CONSTRAINT report_runs_type');
        DB::statement('ALTER TABLE report_runs ADD CONSTRAINT report_runs_type CHECK (report_type IN ('.self::quoted(self::TYPES).'))');

        Schema::dropIfExists('state_profiles');
    }

    /** @param list<string> $values */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => "'{$v}'", $values));
    }
};
