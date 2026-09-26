<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Community information (Phase 2C): religious institutions, vulnerable
 * population statistics (aggregate counts only, never names) and community
 * security notes (classified).
 *
 * Enforced here, not only in the application:
 * - community in the same LGA, LGA in the same state, denomination of the
 *   same religion (composite foreign keys);
 * - an institution's location inside its LGA boundary, when there is one;
 * - one figure per LGA/community/category/date (NULLS NOT DISTINCT, so
 *   LGA-wide figures are unique too);
 * - a resolved note has a resolution date.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Targets of the composite foreign keys below.
        DB::statement('ALTER TABLE communities ADD CONSTRAINT communities_id_lga_id_unique UNIQUE (id, lga_id)');
        DB::statement('ALTER TABLE denominations ADD CONSTRAINT denominations_id_religion_id_unique UNIQUE (id, religion_id)');

        Schema::create('religious_institutions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('community_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('religion_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('denomination_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->integer('estimated_congregation')->nullable();
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            self::sameLgaAndState($table, 'religious_institutions');
            $table->foreign(['denomination_id', 'religion_id'], 'religious_institutions_denomination_same_religion_foreign')
                ->references(['id', 'religion_id'])->on('denominations')->restrictOnDelete()->restrictOnUpdate();
            $table->spatialIndex('location');
        });

        DB::statement("ALTER TABLE religious_institutions ADD CONSTRAINT religious_institutions_type_valid CHECK (type IN ('church', 'mosque', 'shrine', 'islamiyya_school', 'mission_school', 'other'))");
        DB::statement('ALTER TABLE religious_institutions ADD CONSTRAINT religious_institutions_congregation_non_negative CHECK (estimated_congregation IS NULL OR estimated_congregation >= 0)');
        DB::statement('CREATE TRIGGER religious_institutions_location_in_lga BEFORE INSERT OR UPDATE OF location, lga_id ON religious_institutions FOR EACH ROW EXECUTE FUNCTION buffalo_community_location_in_lga()');

        // Aggregate counts only: no names, no notes, no identifying details.
        Schema::create('vulnerable_population_stats', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('community_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('category', 32);
            $table->integer('adult_male')->default(0);
            $table->integer('adult_female')->default(0);
            $table->integer('child_male')->default(0);
            $table->integer('child_female')->default(0);
            $table->date('as_of_date');
            $table->string('source', 150);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            self::sameLgaAndState($table, 'vulnerable_population_stats');
        });

        DB::statement("ALTER TABLE vulnerable_population_stats ADD CONSTRAINT vulnerable_population_stats_category_valid CHECK (category IN ('mental_health_condition', 'physical_disability', 'elderly_without_support', 'unaccompanied_minors', 'internally_displaced', 'other'))");
        DB::statement('ALTER TABLE vulnerable_population_stats ADD CONSTRAINT vulnerable_population_stats_counts_non_negative CHECK (adult_male >= 0 AND adult_female >= 0 AND child_male >= 0 AND child_female >= 0)');
        DB::statement('CREATE UNIQUE INDEX vulnerable_population_stats_one_figure ON vulnerable_population_stats (lga_id, community_id, category, as_of_date) NULLS NOT DISTINCT WHERE deleted_at IS NULL');

        Schema::create('community_security_notes', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('community_id')->index()->constrained()->restrictOnDelete();
            $table->string('category', 32);
            $table->string('title', 200);
            $table->text('description');
            $table->string('status', 16);
            $table->string('classification', 16)->default('restricted');
            $table->date('observed_at');
            $table->date('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            self::sameLgaAndState($table, 'community_security_notes');
            $table->index(['lga_id', 'status']);
        });

        DB::statement("ALTER TABLE community_security_notes ADD CONSTRAINT community_security_notes_category_valid CHECK (category IN ('land_dispute', 'chieftaincy_dispute', 'communal_tension', 'farmer_herder', 'youth_restiveness', 'cult_activity', 'political_tension', 'religious_tension', 'boundary_dispute', 'other'))");
        DB::statement("ALTER TABLE community_security_notes ADD CONSTRAINT community_security_notes_status_valid CHECK (status IN ('active', 'monitoring', 'resolved'))");
        DB::statement("ALTER TABLE community_security_notes ADD CONSTRAINT community_security_notes_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement("ALTER TABLE community_security_notes ADD CONSTRAINT community_security_notes_resolved_has_date CHECK (status <> 'resolved' OR resolved_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('community_security_notes');
        Schema::dropIfExists('vulnerable_population_stats');
        Schema::dropIfExists('religious_institutions');
        DB::statement('ALTER TABLE denominations DROP CONSTRAINT IF EXISTS denominations_id_religion_id_unique');
        DB::statement('ALTER TABLE communities DROP CONSTRAINT IF EXISTS communities_id_lga_id_unique');
    }

    /**
     * (lga_id, state_id) must match the LGA; (community_id, lga_id) must
     * match the community (a null community is not checked).
     */
    private static function sameLgaAndState(Blueprint $table, string $name): void
    {
        $table->foreign(['lga_id', 'state_id'], "{$name}_lga_same_state_foreign")
            ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
        $table->foreign(['community_id', 'lga_id'], "{$name}_community_same_lga_foreign")
            ->references(['id', 'lga_id'])->on('communities')->restrictOnDelete()->restrictOnUpdate();
    }
};
