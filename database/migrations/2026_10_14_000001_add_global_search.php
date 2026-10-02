<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global search (Phase 7B).
 *
 * - Full-text `search_vector` columns (generated, GIN) for threat reports
 *   (title A, description B) and flashpoints (name A, description B), like
 *   incidents. Threat source notes are never indexed.
 * - Trigram `search_name` columns (generated, GIN) for organization sites,
 *   LGAs and senatorial districts, so every provider uses index-backed
 *   operators (CLAUDE.md decisions log); `upper(code)` indexes for exact
 *   LGA and district codes.
 * - `search_logs`: one row per search request, the query only as a SHA-256
 *   hash (a query may contain a name or a NIN). Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE threat_reports ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (setweight(to_tsvector('english', coalesce(title, '')), 'A') || setweight(to_tsvector('english', coalesce(description, '')), 'B')) STORED");
        DB::statement('CREATE INDEX threat_reports_search_vector_gin ON threat_reports USING gin (search_vector)');

        DB::statement("ALTER TABLE flashpoints ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (setweight(to_tsvector('english', coalesce(name, '')), 'A') || setweight(to_tsvector('english', coalesce(description, '')), 'B')) STORED");
        DB::statement('CREATE INDEX flashpoints_search_vector_gin ON flashpoints USING gin (search_vector)');

        foreach (['organization_sites', 'lgas', 'senatorial_districts'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN search_name text GENERATED ALWAYS AS (buffalo_search_text(name)) STORED");
            DB::statement("CREATE INDEX {$table}_search_name_trgm ON {$table} USING gin (search_name gin_trgm_ops)");
        }

        // Exact code lookups ("IKJ") next to the name match (BitmapOr).
        DB::statement('CREATE INDEX lgas_code_upper ON lgas (upper(code))');
        DB::statement('CREATE INDEX senatorial_districts_code_upper ON senatorial_districts (upper(code))');

        Schema::create('search_logs', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('query_hash', 64);
            $table->string('query_type', 16);
            $table->integer('result_count');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE search_logs ADD CONSTRAINT search_logs_query_type CHECK (query_type IN ('reference', 'nin', 'phone', 'text'))");
        DB::statement("ALTER TABLE search_logs ADD CONSTRAINT search_logs_query_hash CHECK (query_hash ~ '^[0-9a-f]{64}$')");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER search_logs_immutable
                BEFORE UPDATE OR DELETE ON search_logs
                FOR EACH ROW EXECUTE FUNCTION buffalo_reject_audit_change();

            CREATE TRIGGER search_logs_no_truncate
                BEFORE TRUNCATE ON search_logs
                FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_audit_change();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('search_logs');
        DB::statement('DROP INDEX IF EXISTS lgas_code_upper');
        DB::statement('DROP INDEX IF EXISTS senatorial_districts_code_upper');

        foreach (['organization_sites', 'lgas', 'senatorial_districts'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS search_name");
        }

        DB::statement('ALTER TABLE flashpoints DROP COLUMN IF EXISTS search_vector');
        DB::statement('ALTER TABLE threat_reports DROP COLUMN IF EXISTS search_vector');
    }
};
