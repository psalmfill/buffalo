<?php

use App\Enums\ArrestStatus;
use App\Enums\OccurrencePrecision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arrests (Phase 11C).
 *
 * - `offence_types`: administrator-managed lookup in the Phase 2A pattern
 *   (shared columns, code format, DELETE/TRUNCATE rejected), with an
 *   optional threat type for analytics. Seeded by ReferenceDataSeeder.
 * - `arrests`: jurisdiction-scoped (composite FKs) and classified.
 *   Reference ARR/<STATE>/<YEAR>/<5 digits>, gap-free per state and year
 *   (`arrest_counters`, as for threat reports). The formation must belong
 *   to the agency and the point must lie in the LGA (triggers).
 * - `arrest_persons` and `arrest_person_offences` carry their own
 *   state_id/lga_id bound to the arrest's (cascading composite FKs), so
 *   they are scoped on their own place (CLAUDE.md access rule 9). A person
 *   on record, or a description of an unidentified one.
 * - `arrest_status_histories`: append-only (trigger), per arrest or per
 *   arrested person; the place follows the arrest.
 * - `record_match_events.context` gains `arrest_entry`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offence_types', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->string('code', 64);
            $table->text('description')->nullable();
            // The threat category an offence counts under in analytics.
            $table->foreignId('threat_type_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('code', 'offence_types_code_unique');
        });
        DB::statement("ALTER TABLE offence_types ADD CONSTRAINT offence_types_code_format CHECK (code ~ '^[a-z][a-z0-9_]*$')");
        DB::statement('CREATE TRIGGER offence_types_no_delete BEFORE DELETE ON offence_types FOR EACH ROW EXECUTE FUNCTION buffalo_reject_reference_delete()');
        DB::statement('CREATE TRIGGER offence_types_no_truncate BEFORE TRUNCATE ON offence_types FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_reference_delete()');

        Schema::create('arrest_counters', function (Blueprint $table) {
            $table->foreignId('state_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_value');
            $table->primary(['state_id', 'year']);
        });

        $statuses = implode(', ', array_map(fn (ArrestStatus $s) => "'{$s->value}'", ArrestStatus::cases()));
        $precisions = implode(', ', array_map(fn (OccurrencePrecision $p) => "'{$p->value}'", OccurrencePrecision::cases()));

        Schema::create('arrests', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('reference_no', 24)->unique();
            $table->timestampTz('arrested_at');
            $table->string('arrested_at_precision', 16)->default('exact');
            $table->foreignId('security_agency_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('agency_formation_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('community_id')->nullable()->index();
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->text('location_description')->nullable();
            $table->foreignId('incident_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('in_custody');
            $table->text('notes')->nullable();
            $table->text('notes_text')->nullable();
            $table->string('classification', 16)->default('restricted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['lga_id', 'state_id'], 'arrests_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['community_id', 'lga_id'], 'arrests_community_same_lga_foreign')
                ->references(['id', 'lga_id'])->on('communities')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id', 'lga_id', 'state_id'], 'arrests_id_lga_state_unique');
            $table->spatialIndex('location');
            $table->index(['state_id', 'arrested_at']);
        });
        DB::statement("ALTER TABLE arrests ADD CONSTRAINT arrests_status_valid CHECK (status IN ({$statuses}))");
        DB::statement("ALTER TABLE arrests ADD CONSTRAINT arrests_precision_valid CHECK (arrested_at_precision IN ({$precisions}))");
        DB::statement("ALTER TABLE arrests ADD CONSTRAINT arrests_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        // Full text on the plain text copy of the notes (Phase 11B), never the markup.
        DB::statement("ALTER TABLE arrests ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(notes_text, ''))) STORED");
        DB::statement('CREATE INDEX arrests_search_vector_index ON arrests USING gin (search_vector)');
        DB::statement('CREATE TRIGGER arrests_location_in_lga BEFORE INSERT OR UPDATE OF location, lga_id ON arrests FOR EACH ROW EXECUTE FUNCTION buffalo_community_location_in_lga()');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_arrest_agency_formation() RETURNS trigger AS $$
            BEGIN
                IF NEW.agency_formation_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM agency_formations WHERE id = NEW.agency_formation_id AND security_agency_id = NEW.security_agency_id
                ) THEN
                    RAISE EXCEPTION 'an arrest''s formation must belong to the arresting agency'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER arrests_formation BEFORE INSERT OR UPDATE OF agency_formation_id, security_agency_id ON arrests FOR EACH ROW EXECUTE FUNCTION buffalo_arrest_agency_formation()');

        Schema::create('arrest_persons', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('arrest_id')->index();
            $table->foreignId('state_id')->index();
            $table->foreignId('lga_id')->index();
            $table->foreignId('person_id')->nullable()->index()->constrained('persons')->restrictOnDelete();
            $table->text('unidentified_description')->nullable();
            $table->string('status', 32)->default('in_custody');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['arrest_id', 'lga_id', 'state_id'], 'arrest_persons_arrest_place_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('arrests')->restrictOnDelete()->cascadeOnUpdate();
            $table->unique(['id', 'lga_id', 'state_id'], 'arrest_persons_id_lga_state_unique');
        });
        DB::statement("ALTER TABLE arrest_persons ADD CONSTRAINT arrest_persons_status_valid CHECK (status IN ({$statuses}))");
        DB::statement("ALTER TABLE arrest_persons ADD CONSTRAINT arrest_persons_identified_or_described CHECK (person_id IS NOT NULL OR (unidentified_description IS NOT NULL AND btrim(unidentified_description) <> ''))");
        DB::statement('CREATE UNIQUE INDEX arrest_persons_person_unique ON arrest_persons (arrest_id, person_id) WHERE deleted_at IS NULL AND person_id IS NOT NULL');

        Schema::create('arrest_person_offences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arrest_person_id')->index();
            $table->foreignId('state_id')->index();
            $table->foreignId('lga_id')->index();
            $table->foreignId('offence_type_id')->index()->constrained()->restrictOnDelete();
            $table->text('details')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['arrest_person_id', 'lga_id', 'state_id'], 'arrest_person_offences_place_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('arrest_persons')->restrictOnDelete()->cascadeOnUpdate();
        });
        DB::statement('CREATE UNIQUE INDEX arrest_person_offences_unique ON arrest_person_offences (arrest_person_id, offence_type_id) WHERE deleted_at IS NULL');

        Schema::create('arrest_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arrest_id')->index();
            $table->foreignId('arrest_person_id')->nullable()->index()->constrained('arrest_persons')->restrictOnDelete();
            $table->foreignId('state_id')->index();
            $table->foreignId('lga_id')->index();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('comment');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('changed_at');

            $table->foreign(['arrest_id', 'lga_id', 'state_id'], 'arrest_status_histories_place_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('arrests')->restrictOnDelete()->cascadeOnUpdate();
        });
        DB::statement("ALTER TABLE arrest_status_histories ADD CONSTRAINT arrest_status_histories_to_valid CHECK (to_status IN ({$statuses}))");
        DB::statement("ALTER TABLE arrest_status_histories ADD CONSTRAINT arrest_status_histories_comment_present CHECK (btrim(comment) <> '')");
        // Append-only; only the place may follow the arrest (cascading FK).
        DB::statement('CREATE TRIGGER arrest_status_histories_append_only BEFORE UPDATE OR DELETE ON arrest_status_histories FOR EACH ROW EXECUTE FUNCTION buffalo_append_only_follow_place()');
        DB::statement('CREATE TRIGGER arrest_status_histories_no_truncate BEFORE TRUNCATE ON arrest_status_histories FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_audit_change()');

        DB::statement('ALTER TABLE record_match_events DROP CONSTRAINT record_match_events_context_valid');
        DB::statement("ALTER TABLE record_match_events ADD CONSTRAINT record_match_events_context_valid CHECK (context IN ('person_search', 'person_create', 'incident_entry', 'arrest_entry'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE record_match_events DROP CONSTRAINT record_match_events_context_valid');
        DB::statement("ALTER TABLE record_match_events ADD CONSTRAINT record_match_events_context_valid CHECK (context IN ('person_search', 'person_create', 'incident_entry'))");

        Schema::dropIfExists('arrest_status_histories');
        Schema::dropIfExists('arrest_person_offences');
        Schema::dropIfExists('arrest_persons');
        Schema::dropIfExists('arrests');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_arrest_agency_formation()');
        Schema::dropIfExists('arrest_counters');
        Schema::dropIfExists('offence_types');
    }
};
