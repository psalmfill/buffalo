<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidating a duplicate incident into the original (Phase 10B.1).
 *
 * - incident_updates.copied_from_update_id: a follow-up copied from the
 *   duplicate (the attribution "Originally recorded on … by … at …" is read
 *   from that row). Nullable: existing rows' hashes are unchanged, since the
 *   audit canonical form strips nulls.
 * - incident_evidence.consolidated_from_incident_id: evidence moved from a
 *   duplicate ("From BUF/…").
 * - incident_consolidations: one row per consolidation with its manifest.
 *   Append-only and hash-chained; its place follows the original incident
 *   (composite FK ON UPDATE CASCADE, like incident_updates), so state_id
 *   and lga_id are left out of the hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_updates', function (Blueprint $table) {
            $table->foreignId('copied_from_update_id')->nullable()->constrained('incident_updates')->restrictOnDelete();
        });

        Schema::table('incident_evidence', function (Blueprint $table) {
            $table->foreignId('consolidated_from_incident_id')->nullable()->constrained('incidents')->restrictOnDelete();
        });

        Schema::create('incident_consolidations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('original_incident_id')->constrained('incidents')->restrictOnDelete();
            $table->foreignId('duplicate_incident_id')->unique()->constrained('incidents')->restrictOnDelete();
            $table->foreignId('state_id')->constrained('states')->restrictOnDelete();
            $table->foreignId('lga_id')->constrained('lgas')->restrictOnDelete();
            $table->jsonb('manifest');
            $table->foreignId('consolidated_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('consolidated_at');
            $table->bigInteger('chain_seq');
            $table->char('prev_hash', 64);
            $table->char('row_hash', 64);
            $table->unique('chain_seq');
            $table->index('original_incident_id');
            $table->foreign(['original_incident_id', 'lga_id', 'state_id'], 'incident_consolidations_original_place_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('incidents')->cascadeOnUpdate()->restrictOnDelete();
        });

        DB::statement('ALTER TABLE incident_consolidations ADD CONSTRAINT incident_consolidations_distinct CHECK (original_incident_id <> duplicate_incident_id)');

        DB::table('audit_chain_heads')->insert([
            'table_name' => 'incident_consolidations', 'last_seq' => 0,
            'last_hash' => str_repeat('0', 64), 'updated_at' => now(),
        ]);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER incident_consolidations_append_only BEFORE UPDATE OR DELETE ON incident_consolidations
                FOR EACH ROW EXECUTE FUNCTION buffalo_append_only_follow_place();
            CREATE TRIGGER incident_consolidations_no_truncate BEFORE TRUNCATE ON incident_consolidations
                FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_audit_change();
            CREATE TRIGGER zz_incident_consolidations_audit_chain BEFORE INSERT ON incident_consolidations
                FOR EACH ROW EXECUTE FUNCTION buffalo_audit_chain('state_id,lga_id');
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_consolidations');
        DB::table('audit_chain_heads')->where('table_name', 'incident_consolidations')->delete();
        Schema::table('incident_evidence', fn (Blueprint $table) => $table->dropConstrainedForeignId('consolidated_from_incident_id'));
        Schema::table('incident_updates', fn (Blueprint $table) => $table->dropConstrainedForeignId('copied_from_update_id'));
    }
};
