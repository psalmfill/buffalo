<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persons (Phase 3A): the person record, roles, aliases and associations.
 *
 * - Jurisdiction: state_id/lga_id are the owning jurisdiction (composite
 *   foreign key to lgas); the optional community must belong to the LGA.
 * - Encryption: date_of_birth, nin, phone and address hold CipherSweet
 *   ciphertext only. CHECK constraints reject anything that is not
 *   ciphertext, so a raw query can never store these values in clear.
 *   Their blind indexes live in blind_indexes (dob_bidx, nin_bidx,
 *   phone_bidx).
 * - Search: search_name / search_alias are generated columns (lowercased,
 *   unaccented, whitespace-normalised) with trigram GIN indexes.
 */
return new class extends Migration
{
    /** Ciphertext prefixes of the CipherSweet backends (nacl, fips, boring). */
    private const CIPHERTEXT = "'^(nacl|fips|brng):[A-Za-z0-9_=-]+$'";

    public function up(): void
    {
        // IMMUTABLE, so it can back generated columns: lowercased, unaccented,
        // trimmed, single-spaced.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_search_text(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT lower(regexp_replace(btrim(buffalo_unaccent($1)), '\s+', ' ', 'g')) $$
        SQL);

        Schema::create('persons', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('community_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('other_names', 150)->nullable();
            $table->string('gender', 10);
            $table->text('date_of_birth')->nullable();
            $table->smallInteger('approximate_age')->nullable();
            $table->text('nin')->nullable();
            $table->text('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('occupation', 150)->nullable();
            $table->string('nationality', 100)->default('Nigerian');
            $table->text('description')->nullable();
            $table->string('classification', 16)->default('restricted');
            $table->boolean('is_deceased')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['lga_id', 'state_id'], 'persons_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['community_id', 'lga_id'], 'persons_community_same_lga_foreign')
                ->references(['id', 'lga_id'])->on('communities')->restrictOnDelete()->restrictOnUpdate();
            $table->index('updated_at');
        });

        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_gender_valid CHECK (gender IN ('male', 'female', 'unknown'))");
        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement('ALTER TABLE persons ADD CONSTRAINT persons_approximate_age_valid CHECK (approximate_age IS NULL OR approximate_age BETWEEN 0 AND 130)');
        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_names_present CHECK (btrim(first_name) <> '' AND btrim(last_name) <> '')");

        foreach (['date_of_birth', 'nin', 'phone', 'address'] as $column) {
            DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_{$column}_encrypted CHECK ({$column} IS NULL OR {$column} ~ ".self::CIPHERTEXT.')');
        }

        DB::statement("ALTER TABLE persons ADD COLUMN search_name text GENERATED ALWAYS AS (buffalo_search_text(first_name || ' ' || coalesce(other_names, '') || ' ' || last_name)) STORED");
        DB::statement('CREATE INDEX persons_search_name_trgm ON persons USING gin (search_name gin_trgm_ops)');

        Schema::create('person_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $table->string('role', 32);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['person_id', 'role']);
            $table->index(['role', 'is_active']);
        });

        DB::statement("ALTER TABLE person_roles ADD CONSTRAINT person_roles_role_valid CHECK (role IN ('political_leader', 'traditional_ruler', 'religious_leader', 'community_leader', 'person_of_interest'))");

        Schema::create('person_aliases', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('person_id')->index()->constrained('persons')->restrictOnDelete();
            $table->string('alias', 150);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE person_aliases ADD CONSTRAINT person_aliases_alias_present CHECK (btrim(alias) <> '')");
        DB::statement('ALTER TABLE person_aliases ADD COLUMN search_alias text GENERATED ALWAYS AS (buffalo_search_text(alias)) STORED');
        DB::statement('CREATE INDEX person_aliases_search_alias_trgm ON person_aliases USING gin (search_alias gin_trgm_ops)');
        DB::statement('CREATE UNIQUE INDEX person_aliases_unique ON person_aliases (person_id, search_alias) WHERE deleted_at IS NULL');

        Schema::create('person_associations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('person_id')->index()->constrained('persons')->restrictOnDelete();
            $table->foreignId('associated_person_id')->index()->constrained('persons')->restrictOnDelete();
            $table->string('relationship', 32);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE person_associations ADD CONSTRAINT person_associations_relationship_valid CHECK (relationship IN ('family', 'associate', 'accomplice', 'employer', 'employee', 'political_ally', 'rival', 'other'))");
        DB::statement('ALTER TABLE person_associations ADD CONSTRAINT person_associations_not_self CHECK (person_id <> associated_person_id)');
        // Once per pair, whichever way round it was recorded.
        DB::statement('CREATE UNIQUE INDEX person_associations_pair_unique ON person_associations (LEAST(person_id, associated_person_id), GREATEST(person_id, associated_person_id)) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('person_associations');
        Schema::dropIfExists('person_aliases');
        Schema::dropIfExists('person_roles');
        Schema::dropIfExists('persons');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_search_text(text)');
    }
};
