<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Incidents (Phase 5A): the incident record and its related data.
 *
 * - incidents: jurisdiction-scoped (composite FKs) and classified; type-
 *   specific `details` (jsonb) validated on save against the type's schema;
 *   a generated English full-text `search_vector` (GIN). Workflow columns
 *   arrive in 5C.
 * - Child tables (casualties, persons, criminal groups, agencies,
 *   properties) carry their own state_id/lga_id (access rule 9), bound to
 *   the incident's by a composite FK with ON UPDATE CASCADE: when the
 *   incident moves to another LGA, every child row follows.
 * - record_access_requests.incident_id: the requester's own incident cited
 *   in an access request.
 *
 * New date-time columns are timestamptz (CLAUDE.md, Phase 5A.0).
 */
return new class extends Migration
{
    private const CHILDREN = ['incident_casualties', 'incident_persons', 'incident_criminal_groups', 'incident_agencies', 'incident_properties'];

    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('reference_no', 32)->nullable();
            $table->foreignId('incident_type_id')->index()->constrained()->restrictOnDelete();
            $table->jsonb('details')->default(DB::raw("'{}'::jsonb"));
            $table->integer('details_schema_version')->default(1);
            $table->string('title', 200);
            $table->text('description');
            $table->string('severity', 16);
            $table->timestampTz('occurred_at');
            $table->string('occurred_at_precision', 16)->default('exact');
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('community_id')->nullable()->index();
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->text('location_description')->nullable();
            $table->text('action_taken')->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('classification', 16)->default('restricted');
            $table->foreignId('reporting_officer_id')->index()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['lga_id', 'state_id'], 'incidents_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['community_id', 'lga_id'], 'incidents_community_same_lga_foreign')
                ->references(['id', 'lga_id'])->on('communities')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id', 'lga_id', 'state_id'], 'incidents_id_lga_state_unique');
            $table->spatialIndex('location');
            $table->index(['status', 'occurred_at']);
        });

        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_severity_valid CHECK (severity IN ('low', 'medium', 'high', 'critical'))");
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_precision_valid CHECK (occurred_at_precision IN ('exact', 'approximate', 'date_only'))");
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_status_valid CHECK (status IN ('draft', 'submitted', 'returned', 'verified', 'under_investigation', 'resolved', 'closed', 'invalid'))");
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_details_object CHECK (jsonb_typeof(details) = 'object')");
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_title_not_blank CHECK (btrim(title) <> '' AND btrim(description) <> '')");
        DB::statement('CREATE UNIQUE INDEX incidents_reference_no_unique ON incidents (reference_no) WHERE reference_no IS NOT NULL');
        DB::statement("ALTER TABLE incidents ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(title, '') || ' ' || coalesce(description, ''))) STORED");
        DB::statement('CREATE INDEX incidents_search_vector_gin ON incidents USING gin (search_vector)');
        DB::statement('CREATE TRIGGER incidents_location_in_lga BEFORE INSERT OR UPDATE OF location, lga_id ON incidents FOR EACH ROW EXECUTE FUNCTION buffalo_community_location_in_lga()');

        $this->child('incident_casualties', function (Blueprint $table) {
            $table->string('outcome', 16);
            $table->string('group', 24);
            $table->foreignId('security_agency_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->integer('adult_male')->default(0);
            $table->integer('adult_female')->default(0);
            $table->integer('child_male')->default(0);
            $table->integer('child_female')->default(0);
            $table->integer('unknown')->default(0);
        });
        DB::statement("ALTER TABLE incident_casualties ADD CONSTRAINT incident_casualties_outcome_valid CHECK (outcome IN ('killed', 'injured', 'abducted', 'missing', 'rescued', 'arrested'))");
        DB::statement("ALTER TABLE incident_casualties ADD CONSTRAINT incident_casualties_group_valid CHECK (\"group\" IN ('civilian', 'security_personnel', 'suspect'))");
        DB::statement("ALTER TABLE incident_casualties ADD CONSTRAINT incident_casualties_agency_for_security CHECK ((\"group\" = 'security_personnel') = (security_agency_id IS NOT NULL))");
        DB::statement('ALTER TABLE incident_casualties ADD CONSTRAINT incident_casualties_counts_non_negative CHECK (adult_male >= 0 AND adult_female >= 0 AND child_male >= 0 AND child_female >= 0 AND unknown >= 0)');
        DB::statement('ALTER TABLE incident_casualties ADD CONSTRAINT incident_casualties_some_count CHECK (adult_male + adult_female + child_male + child_female + unknown > 0)');
        DB::statement('CREATE UNIQUE INDEX incident_casualties_unique ON incident_casualties (incident_id, outcome, "group", security_agency_id) NULLS NOT DISTINCT WHERE deleted_at IS NULL');

        $this->child('incident_persons', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->index()->constrained('persons')->restrictOnDelete();
            $table->text('unidentified_description')->nullable();
            $table->string('involvement', 16);
            $table->text('notes')->nullable();
        });
        DB::statement("ALTER TABLE incident_persons ADD CONSTRAINT incident_persons_involvement_valid CHECK (involvement IN ('victim', 'suspect', 'witness', 'informant', 'complainant', 'other'))");
        DB::statement("ALTER TABLE incident_persons ADD CONSTRAINT incident_persons_identified_or_described CHECK (person_id IS NOT NULL OR (unidentified_description IS NOT NULL AND btrim(unidentified_description) <> ''))");

        $this->child('incident_criminal_groups', function (Blueprint $table) {
            $table->foreignId('criminal_group_id')->index()->constrained()->restrictOnDelete();
            $table->string('role', 16);
            $table->text('notes')->nullable();
        });
        DB::statement("ALTER TABLE incident_criminal_groups ADD CONSTRAINT incident_criminal_groups_role_valid CHECK (role IN ('suspected', 'claimed', 'confirmed'))");
        DB::statement('CREATE UNIQUE INDEX incident_criminal_groups_unique ON incident_criminal_groups (incident_id, criminal_group_id) WHERE deleted_at IS NULL');

        $this->child('incident_agencies', function (Blueprint $table) {
            $table->foreignId('security_agency_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('agency_formation_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('role', 24);
            $table->timestampTz('responded_at')->nullable();
            $table->text('notes')->nullable();
        });
        DB::statement("ALTER TABLE incident_agencies ADD CONSTRAINT incident_agencies_role_valid CHECK (role IN ('first_responder', 'lead', 'support', 'notified'))");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_incident_agency_formation() RETURNS trigger AS $$
            BEGIN
                IF NEW.agency_formation_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM agency_formations WHERE id = NEW.agency_formation_id AND security_agency_id = NEW.security_agency_id
                ) THEN
                    RAISE EXCEPTION 'an incident agency''s formation must belong to that agency'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER incident_agencies_formation BEFORE INSERT OR UPDATE OF agency_formation_id, security_agency_id ON incident_agencies FOR EACH ROW EXECUTE FUNCTION buffalo_incident_agency_formation()');

        $this->child('incident_properties', function (Blueprint $table) {
            $table->foreignId('property_type_id')->index()->constrained()->restrictOnDelete();
            $table->text('description')->nullable();
            $table->integer('quantity')->nullable();
            $table->decimal('estimated_value_naira', 15, 2)->nullable();
            $table->foreignId('organization_site_id')->nullable()->index()->constrained()->restrictOnDelete();
        });
        DB::statement('ALTER TABLE incident_properties ADD CONSTRAINT incident_properties_quantity_positive CHECK (quantity IS NULL OR quantity > 0)');
        DB::statement('ALTER TABLE incident_properties ADD CONSTRAINT incident_properties_value_non_negative CHECK (estimated_value_naira IS NULL OR estimated_value_naira >= 0)');

        Schema::table('record_access_requests', function (Blueprint $table) {
            $table->foreignId('incident_id')->nullable()->index()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('record_access_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incident_id');
        });

        foreach (array_reverse(self::CHILDREN) as $table) {
            Schema::dropIfExists($table);
        }

        Schema::dropIfExists('incidents');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_incident_agency_formation()');
    }

    /**
     * A child table: ULID, the incident, its own state_id/lga_id bound to
     * the incident's (cascading on update), authors, timestamps, soft deletes.
     */
    private function child(string $name, Closure $columns): void
    {
        Schema::create($name, function (Blueprint $table) use ($name, $columns) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('incident_id')->index();
            $table->foreignId('state_id')->index();
            $table->foreignId('lga_id')->index();
            $columns($table);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['incident_id', 'lga_id', 'state_id'], "{$name}_incident_place_foreign")
                ->references(['id', 'lga_id', 'state_id'])->on('incidents')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign(['lga_id', 'state_id'], "{$name}_lga_same_state_foreign")
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
        });
    }
};
