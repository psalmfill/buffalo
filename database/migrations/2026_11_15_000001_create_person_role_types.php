<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Person role types become an administrator-managed lookup (Phase 11A.1),
 * in the Phase 2A pattern (shared columns, code format, DELETE/TRUNCATE
 * rejected), plus `is_system`.
 *
 * - System types (the five former enum values) keep their codes, because
 *   code depends on them, and stay active (CHECK). A trigger refuses any
 *   change of a code or of is_system, behind the model guard.
 * - person_roles.role (the enum value) becomes person_role_type_id,
 *   backfilled by code; uniqueness is (person_id, person_role_type_id).
 *
 * The eight types are inserted here (ON CONFLICT DO NOTHING), so existing
 * installations get them with the migration; ReferenceDataSeeder creates
 * only missing ones and never overwrites an administrator's edits.
 */
return new class extends Migration
{
    /** code => [name, description, sort_order, is_system] */
    public const TYPES = [
        'political_leader' => ['Political leader', 'Added automatically with a political office.', 10, true],
        'traditional_ruler' => ['Traditional ruler', 'Required to be named traditional ruler of a community.', 20, true],
        'religious_leader' => ['Religious leader', 'Required to be named leader of a religious institution.', 30, true],
        'community_leader' => ['Community leader', null, 40, true],
        'person_of_interest' => ['Person of interest', 'Set only by the persons of interest module.', 90, true],
        'youth_leader' => ['Youth leader', null, 50, false],
        'women_leader' => ['Women leader', null, 60, false],
        'market_leader' => ['Market or trade association leader', null, 70, false],
    ];

    public function up(): void
    {
        Schema::create('person_role_types', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->string('code', 64);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('code', 'person_role_types_code_unique');
        });

        DB::statement("ALTER TABLE person_role_types ADD CONSTRAINT person_role_types_code_format CHECK (code ~ '^[a-z][a-z0-9_]*$')");
        DB::statement('ALTER TABLE person_role_types ADD CONSTRAINT person_role_types_system_active CHECK (NOT is_system OR is_active)');
        DB::statement('CREATE TRIGGER person_role_types_no_delete BEFORE DELETE ON person_role_types FOR EACH ROW EXECUTE FUNCTION buffalo_reject_reference_delete()');
        DB::statement('CREATE TRIGGER person_role_types_no_truncate BEFORE TRUNCATE ON person_role_types FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_reference_delete()');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_person_role_types_fixed() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.code IS DISTINCT FROM OLD.code OR NEW.is_system IS DISTINCT FROM OLD.is_system THEN
                    RAISE EXCEPTION 'person_role_types: the code and the system flag cannot be changed' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER person_role_types_fixed BEFORE UPDATE ON person_role_types
                FOR EACH ROW EXECUTE FUNCTION buffalo_person_role_types_fixed();
            SQL);

        foreach (self::TYPES as $code => [$name, $description, $sort, $system]) {
            DB::table('person_role_types')->insertOrIgnore([
                'ulid' => strtolower((string) Str::ulid()),
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'sort_order' => $sort,
                'is_active' => true,
                'is_system' => $system,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('person_roles', function (Blueprint $table) {
            $table->foreignId('person_role_type_id')->nullable()->constrained('person_role_types')->restrictOnDelete();
        });

        DB::statement('UPDATE person_roles r SET person_role_type_id = t.id FROM person_role_types t WHERE t.code = r.role');

        $unmapped = DB::table('person_roles')->whereNull('person_role_type_id')->count();
        if ($unmapped > 0) {
            throw new RuntimeException("{$unmapped} person role(s) have a role code with no person role type.");
        }

        Schema::table('person_roles', function (Blueprint $table) {
            $table->dropUnique('person_roles_person_id_role_unique');
            $table->dropColumn('role');
        });
        DB::statement('ALTER TABLE person_roles ALTER COLUMN person_role_type_id SET NOT NULL');
        Schema::table('person_roles', function (Blueprint $table) {
            $table->unique(['person_id', 'person_role_type_id'], 'person_roles_person_type_unique');
            $table->index(['person_role_type_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('person_roles', function (Blueprint $table) {
            $table->string('role', 32)->nullable();
        });
        DB::statement('UPDATE person_roles r SET role = t.code FROM person_role_types t WHERE t.id = r.person_role_type_id');
        Schema::table('person_roles', function (Blueprint $table) {
            $table->dropUnique('person_roles_person_type_unique');
            $table->dropConstrainedForeignId('person_role_type_id');
            $table->unique(['person_id', 'role']);
        });
        Schema::dropIfExists('person_role_types');
        DB::unprepared('DROP FUNCTION IF EXISTS buffalo_person_role_types_fixed()');
    }
};
