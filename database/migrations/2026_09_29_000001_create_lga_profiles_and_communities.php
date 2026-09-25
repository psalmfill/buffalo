<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LGA profiles and communities (Phase 2B): the first intelligence content,
 * jurisdiction-scoped by state_id/lga_id.
 *
 * Consistency is enforced here, not only in the application:
 * - (lga_id, state_id) must match the LGA; a community's ward must belong
 *   to its LGA (composite foreign keys);
 * - a community's location must lie inside its LGA's boundary when the LGA
 *   has one (trigger);
 * - community names are unique per LGA, case-insensitively, among
 *   non-deleted rows (partial unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        // unaccent() is only STABLE; an IMMUTABLE wrapper (fixed dictionary)
        // can be used in generated columns and indexes.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_unaccent(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
        SQL);

        // Target of the composite foreign key below.
        DB::statement('ALTER TABLE wards ADD CONSTRAINT wards_id_lga_id_unique UNIQUE (id, lga_id)');

        Schema::create('lga_profiles', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lga_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->text('history')->nullable();
            $table->text('economic_activities')->nullable();
            $table->jsonb('terrain')->default(DB::raw("'[]'::jsonb"));
            $table->text('major_roads')->nullable();
            $table->text('waterways')->nullable();
            $table->text('borders')->nullable();
            $table->text('general_notes')->nullable();
            $table->timestampTz('last_reviewed_at')->nullable();
            $table->foreignId('last_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['lga_id', 'state_id'], 'lga_profiles_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE lga_profiles ADD CONSTRAINT lga_profiles_terrain_array CHECK (jsonb_typeof(terrain) = 'array')");

        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('ward_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->jsonb('other_names')->default(DB::raw("'[]'::jsonb"));
            $table->string('type', 16);
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->integer('population_estimate')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['lga_id', 'state_id'], 'communities_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['ward_id', 'lga_id'], 'communities_ward_same_lga_foreign')
                ->references(['id', 'lga_id'])->on('wards')->restrictOnDelete()->restrictOnUpdate();

            $table->spatialIndex('location');
        });

        DB::statement("ALTER TABLE communities ADD CONSTRAINT communities_type_valid CHECK (type IN ('village', 'town', 'settlement', 'camp', 'estate', 'waterside', 'other'))");
        DB::statement("ALTER TABLE communities ADD CONSTRAINT communities_other_names_array CHECK (jsonb_typeof(other_names) = 'array')");
        DB::statement('ALTER TABLE communities ADD CONSTRAINT communities_population_non_negative CHECK (population_estimate IS NULL OR population_estimate >= 0)');

        DB::statement('CREATE UNIQUE INDEX communities_lga_name_unique ON communities (lga_id, lower(name)) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX communities_name_trgm ON communities USING gin (name gin_trgm_ops)');

        // Every name (name + other names), lowercased and without accents,
        // for fuzzy search.
        DB::statement("ALTER TABLE communities ADD COLUMN search_names text GENERATED ALWAYS AS (lower(buffalo_unaccent(name || ' ' || other_names::text))) STORED");
        DB::statement('CREATE INDEX communities_search_names_trgm ON communities USING gin (search_names gin_trgm_ops)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_community_location_in_lga() RETURNS trigger AS $$
            DECLARE
                lga_boundary geography;
            BEGIN
                IF NEW.location IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT boundary INTO lga_boundary FROM lgas WHERE id = NEW.lga_id;

                IF lga_boundary IS NOT NULL AND NOT ST_Covers(lga_boundary, NEW.location) THEN
                    RAISE EXCEPTION 'community location is outside its LGA boundary'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('CREATE TRIGGER communities_location_in_lga BEFORE INSERT OR UPDATE OF location, lga_id ON communities FOR EACH ROW EXECUTE FUNCTION buffalo_community_location_in_lga()');
    }

    public function down(): void
    {
        Schema::dropIfExists('communities');
        Schema::dropIfExists('lga_profiles');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_community_location_in_lga()');
        DB::statement('ALTER TABLE wards DROP CONSTRAINT IF EXISTS wards_id_lga_id_unique');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_unaccent(text)');
    }
};
