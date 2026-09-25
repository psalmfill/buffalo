<?php

use App\Enums\AgencyType;
use App\Enums\IncidentCategory;
use App\Enums\Severity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administrator-managed lookup tables (Phase 2A). Values are deactivated,
 * never deleted: records will reference them. A trigger rejects DELETE as a
 * second line of defence behind the model guard (IsReferenceData).
 *
 * `code` is lowercase snake_case and fixed once created.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['incident_types', 'threat_types', 'security_agencies', 'religions', 'denominations', 'property_types'];

    public function up(): void
    {
        $this->lookup('incident_types', function (Blueprint $table) {
            $table->string('category', 32);
            $table->string('default_severity', 16);
            $table->jsonb('details_schema')->default(DB::raw("'[]'::jsonb"));
            $table->unsignedInteger('schema_version')->default(1);
        });

        $this->lookup('threat_types', function (Blueprint $table) {
            $table->string('category', 32);
        });

        $this->lookup('security_agencies', function (Blueprint $table) {
            $table->string('short_name', 30);
            $table->string('agency_type', 16);
            // Only for state-level outfits (reference data, not scoped).
            $table->foreignId('state_id')->nullable()->index()->constrained()->restrictOnDelete();
        });

        $this->lookup('religions');

        $this->lookup('denominations', function (Blueprint $table) {
            $table->foreignId('religion_id')->index()->constrained()->restrictOnDelete();
        }, uniqueCodeWithin: 'religion_id');

        $this->lookup('property_types');

        $this->checkIn('incident_types', 'category', array_column(IncidentCategory::cases(), 'value'));
        $this->checkIn('incident_types', 'default_severity', array_column(Severity::cases(), 'value'));
        $this->checkIn('threat_types', 'category', array_column(IncidentCategory::cases(), 'value'));
        $this->checkIn('security_agencies', 'agency_type', array_column(AgencyType::cases(), 'value'));

        DB::statement("ALTER TABLE incident_types ADD CONSTRAINT incident_types_details_schema_array CHECK (jsonb_typeof(details_schema) = 'array')");
        DB::statement('ALTER TABLE incident_types ADD CONSTRAINT incident_types_schema_version_positive CHECK (schema_version >= 1)');
        DB::statement("ALTER TABLE security_agencies ADD CONSTRAINT security_agencies_state_only_for_state_outfits CHECK ((agency_type = 'state') = (state_id IS NOT NULL))");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_reject_reference_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% rows are deactivated, never deleted', TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        foreach (self::TABLES as $table) {
            DB::statement("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION buffalo_reject_reference_delete()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_reference_delete()");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }

        DB::statement('DROP FUNCTION IF EXISTS buffalo_reject_reference_delete()');
    }

    /**
     * Columns every lookup table shares, plus $extra.
     */
    private function lookup(string $name, ?Closure $extra = null, ?string $uniqueCodeWithin = null): void
    {
        Schema::create($name, function (Blueprint $table) use ($name, $extra, $uniqueCodeWithin) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->string('code', 64);
            $table->text('description')->nullable();

            if ($extra !== null) {
                $extra($table);
            }

            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $uniqueCodeWithin === null
                ? $table->unique('code', "{$name}_code_unique")
                : $table->unique([$uniqueCodeWithin, 'code'], "{$name}_{$uniqueCodeWithin}_code_unique");
        });

        DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_code_format CHECK (code ~ '^[a-z][a-z0-9_]*$')");
    }

    /**
     * @param  list<string>  $values
     */
    private function checkIn(string $table, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn (string $value) => "'{$value}'", $values));

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_valid CHECK ({$column} IN ({$list}))");
    }
};
