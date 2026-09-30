<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agency formations (Phase 4A): the physical presence of a security agency
 * (commands, divisions, stations, bases, checkpoints). Jurisdiction-scoped
 * (state_id/lga_id) and classified.
 *
 * Database guarantees:
 * - composite FKs (lga_id, state_id) → lgas and (community_id, lga_id) →
 *   communities;
 * - a state agency's formations lie in that agency's state (trigger);
 * - a parent formation belongs to the same agency, and the hierarchy has no
 *   cycles (trigger);
 * - the location lies inside the LGA's boundary when it has one (the Phase 2
 *   trigger function);
 * - a temporary formation has active_from, and active_to is not before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_formations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('security_agency_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('parent_formation_id')->nullable()->index()->constrained('agency_formations')->restrictOnDelete();
            $table->string('name', 200);
            $table->string('formation_type', 32);
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('community_id')->nullable()->index();
            $table->text('address')->nullable();
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->string('official_phone', 32)->nullable();
            $table->string('commander_title', 100)->nullable();
            $table->string('commander_name', 200)->nullable();
            $table->string('commander_rank', 100)->nullable();
            $table->integer('personnel_strength')->nullable();
            $table->boolean('is_temporary')->default(false);
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('classification', 16)->default('restricted');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['lga_id', 'state_id'], 'agency_formations_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['community_id', 'lga_id'], 'agency_formations_community_same_lga_foreign')
                ->references(['id', 'lga_id'])->on('communities')->restrictOnDelete()->restrictOnUpdate();
            $table->spatialIndex('location');
            $table->index(['formation_type', 'status']);
        });

        DB::statement("ALTER TABLE agency_formations ADD CONSTRAINT agency_formations_type_valid CHECK (formation_type IN ('headquarters', 'command', 'division', 'area_command', 'station', 'post', 'base', 'barracks', 'checkpoint', 'outpost', 'office', 'other'))");
        DB::statement("ALTER TABLE agency_formations ADD CONSTRAINT agency_formations_status_valid CHECK (status IN ('active', 'inactive', 'closed'))");
        DB::statement("ALTER TABLE agency_formations ADD CONSTRAINT agency_formations_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement('ALTER TABLE agency_formations ADD CONSTRAINT agency_formations_strength_non_negative CHECK (personnel_strength IS NULL OR personnel_strength >= 0)');
        DB::statement("ALTER TABLE agency_formations ADD CONSTRAINT agency_formations_name_not_blank CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE agency_formations ADD CONSTRAINT agency_formations_temporary_dates CHECK (NOT is_temporary OR active_from IS NOT NULL)');
        DB::statement('ALTER TABLE agency_formations ADD CONSTRAINT agency_formations_dates_ordered CHECK (active_to IS NULL OR active_from IS NULL OR active_to >= active_from)');
        DB::statement('ALTER TABLE agency_formations ADD CONSTRAINT agency_formations_not_own_parent CHECK (parent_formation_id IS NULL OR parent_formation_id <> id)');
        DB::statement('ALTER TABLE agency_formations ADD COLUMN search_name text GENERATED ALWAYS AS (buffalo_search_text(name)) STORED');
        DB::statement('CREATE INDEX agency_formations_search_name_trgm ON agency_formations USING gin (search_name gin_trgm_ops)');

        DB::statement('CREATE TRIGGER agency_formations_location_in_lga BEFORE INSERT OR UPDATE OF location, lga_id ON agency_formations FOR EACH ROW EXECUTE FUNCTION buffalo_community_location_in_lga()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_agency_formation_rules() RETURNS trigger AS $$
            DECLARE
                agency_state bigint;
                parent_agency bigint;
                cursor_id bigint;
                depth integer := 0;
            BEGIN
                SELECT state_id INTO agency_state FROM security_agencies WHERE id = NEW.security_agency_id;

                IF agency_state IS NOT NULL AND agency_state <> NEW.state_id THEN
                    RAISE EXCEPTION 'a state agency''s formation must be in that state'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.parent_formation_id IS NOT NULL THEN
                    SELECT security_agency_id INTO parent_agency FROM agency_formations WHERE id = NEW.parent_formation_id;

                    IF parent_agency IS DISTINCT FROM NEW.security_agency_id THEN
                        RAISE EXCEPTION 'a parent formation must belong to the same agency'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    cursor_id := NEW.parent_formation_id;

                    WHILE cursor_id IS NOT NULL LOOP
                        IF cursor_id = NEW.id THEN
                            RAISE EXCEPTION 'formation hierarchy cannot contain a cycle'
                                USING ERRCODE = 'check_violation';
                        END IF;

                        depth := depth + 1;

                        IF depth > 50 THEN
                            RAISE EXCEPTION 'formation hierarchy is too deep'
                                USING ERRCODE = 'check_violation';
                        END IF;

                        SELECT parent_formation_id INTO cursor_id FROM agency_formations WHERE id = cursor_id;
                    END LOOP;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('CREATE TRIGGER agency_formations_rules BEFORE INSERT OR UPDATE OF security_agency_id, parent_formation_id, state_id ON agency_formations FOR EACH ROW EXECUTE FUNCTION buffalo_agency_formation_rules()');
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_formations');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_agency_formation_rules()');
    }
};
