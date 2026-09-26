<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Political offices, persons of interest and criminal groups (Phase 3B).
 *
 * Consistency is enforced in the database (composite foreign keys, CHECKs):
 * - an office's district and LGA belong to its state, its ward to its LGA;
 *   the office type decides which of them are required;
 * - a POI's state/LGA always equal its person's (composite FK to persons,
 *   ON UPDATE CASCADE);
 * - a group's and each area's LGA belongs to the given state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('political_offices', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('person_id')->index()->constrained('persons')->restrictOnDelete();
            $table->string('office', 32);
            $table->string('office_title', 150)->nullable();
            $table->string('party', 100)->nullable();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('senatorial_district_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('ward_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('constituency_name', 150)->nullable();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['senatorial_district_id', 'state_id'], 'political_offices_district_same_state_foreign')
                ->references(['id', 'state_id'])->on('senatorial_districts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['lga_id', 'state_id'], 'political_offices_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['ward_id', 'lga_id'], 'political_offices_ward_same_lga_foreign')
                ->references(['id', 'lga_id'])->on('wards')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['office', 'state_id']);
        });

        DB::statement("ALTER TABLE political_offices ADD CONSTRAINT political_offices_office_valid CHECK (office IN ('governor', 'deputy_governor', 'senator', 'house_of_reps_member', 'state_assembly_member', 'lga_chairman', 'lga_vice_chairman', 'councillor', 'commissioner', 'special_adviser', 'party_official', 'other'))");
        DB::statement("ALTER TABLE political_offices ADD CONSTRAINT political_offices_senator_has_district CHECK (office <> 'senator' OR senatorial_district_id IS NOT NULL)");
        DB::statement("ALTER TABLE political_offices ADD CONSTRAINT political_offices_lga_offices_have_lga CHECK (office NOT IN ('lga_chairman', 'lga_vice_chairman', 'councillor') OR lga_id IS NOT NULL)");
        DB::statement("ALTER TABLE political_offices ADD CONSTRAINT political_offices_councillor_has_ward CHECK (office <> 'councillor' OR ward_id IS NOT NULL)");
        DB::statement('ALTER TABLE political_offices ADD CONSTRAINT political_offices_ward_needs_lga CHECK (ward_id IS NULL OR lga_id IS NOT NULL)');
        DB::statement('ALTER TABLE political_offices ADD CONSTRAINT political_offices_dates_ordered CHECK (ended_on IS NULL OR ended_on >= started_on)');

        // Target of the POI's composite foreign key: a POI's place is always its person's.
        DB::statement('ALTER TABLE persons ADD CONSTRAINT persons_id_lga_id_state_id_unique UNIQUE (id, lga_id, state_id)');

        Schema::create('persons_of_interest', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->string('risk_level', 16);
            $table->string('status', 24);
            $table->jsonb('offence_categories')->default(DB::raw("'[]'::jsonb"));
            $table->text('known_offences');
            $table->text('modus_operandi')->nullable();
            $table->geography('last_known_location', subtype: 'point', srid: 4326)->nullable();
            $table->text('last_known_location_description')->nullable();
            $table->timestampTz('last_sighted_at')->nullable();
            $table->date('review_due_at');
            $table->timestampTz('last_reviewed_at')->nullable();
            $table->foreignId('last_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            // The due date an overdue reminder was last sent for (one reminder per due date).
            $table->date('review_reminded_for')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['person_id', 'lga_id', 'state_id'], 'persons_of_interest_same_place_as_person_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('persons')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign(['lga_id', 'state_id'], 'persons_of_interest_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['status', 'risk_level']);
            $table->index('review_due_at');
            $table->spatialIndex('last_known_location');
        });

        DB::statement('CREATE UNIQUE INDEX persons_of_interest_one_per_person ON persons_of_interest (person_id) WHERE deleted_at IS NULL');
        DB::statement("ALTER TABLE persons_of_interest ADD CONSTRAINT persons_of_interest_risk_valid CHECK (risk_level IN ('low', 'medium', 'high', 'critical'))");
        DB::statement("ALTER TABLE persons_of_interest ADD CONSTRAINT persons_of_interest_status_valid CHECK (status IN ('at_large', 'under_surveillance', 'detained', 'on_trial', 'convicted', 'deceased', 'cleared'))");
        DB::statement("ALTER TABLE persons_of_interest ADD CONSTRAINT persons_of_interest_offences_array CHECK (jsonb_typeof(offence_categories) = 'array')");
        DB::statement('CREATE INDEX persons_of_interest_offences_gin ON persons_of_interest USING gin (offence_categories)');

        Schema::create('criminal_groups', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->jsonb('other_names')->default(DB::raw("'[]'::jsonb"));
            $table->string('type', 32);
            $table->text('description');
            $table->integer('estimated_size')->nullable();
            $table->string('status', 16);
            $table->string('classification', 16)->default('restricted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['lga_id', 'state_id'], 'criminal_groups_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['type', 'status']);
        });

        DB::statement("ALTER TABLE criminal_groups ADD CONSTRAINT criminal_groups_type_valid CHECK (type IN ('cult', 'bandit_group', 'kidnap_syndicate', 'armed_robbery_gang', 'piracy_gang', 'oil_theft_network', 'terrorist_group', 'militia', 'other'))");
        DB::statement("ALTER TABLE criminal_groups ADD CONSTRAINT criminal_groups_status_valid CHECK (status IN ('active', 'dormant', 'dismantled', 'unknown'))");
        DB::statement("ALTER TABLE criminal_groups ADD CONSTRAINT criminal_groups_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement('ALTER TABLE criminal_groups ADD CONSTRAINT criminal_groups_size_non_negative CHECK (estimated_size IS NULL OR estimated_size >= 0)');
        DB::statement("ALTER TABLE criminal_groups ADD CONSTRAINT criminal_groups_other_names_array CHECK (jsonb_typeof(other_names) = 'array')");
        DB::statement("ALTER TABLE criminal_groups ADD COLUMN search_name text GENERATED ALWAYS AS (buffalo_search_text(name || ' ' || other_names::text)) STORED");
        DB::statement('CREATE INDEX criminal_groups_search_name_trgm ON criminal_groups USING gin (search_name gin_trgm_ops)');

        // Where a group operates. Seen only through the group (whose home
        // jurisdiction decides visibility): an area never grants visibility.
        Schema::create('criminal_group_areas', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('criminal_group_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['lga_id', 'state_id'], 'criminal_group_areas_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('CREATE UNIQUE INDEX criminal_group_areas_unique ON criminal_group_areas (criminal_group_id, lga_id) WHERE deleted_at IS NULL');

        Schema::create('criminal_group_members', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('criminal_group_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->index()->constrained('persons')->restrictOnDelete();
            $table->string('role_in_group', 100)->nullable();
            $table->string('membership_status', 16);
            $table->date('since')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE criminal_group_members ADD CONSTRAINT criminal_group_members_status_valid CHECK (membership_status IN ('suspected', 'confirmed', 'former'))");
        DB::statement('CREATE UNIQUE INDEX criminal_group_members_unique ON criminal_group_members (criminal_group_id, person_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('criminal_group_members');
        Schema::dropIfExists('criminal_group_areas');
        Schema::dropIfExists('criminal_groups');
        Schema::dropIfExists('persons_of_interest');
        DB::statement('ALTER TABLE persons DROP CONSTRAINT IF EXISTS persons_id_lga_id_state_id_unique');
        Schema::dropIfExists('political_offices');
    }
};
