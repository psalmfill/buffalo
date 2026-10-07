<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client decisions required before go-live (Phase 9B.6): who decided what,
 * and when. Append-only and hash-chained like the other audit tables; a
 * new record supersedes the previous one for the same decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('golive_decisions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('decision', 50);
            $table->string('outcome', 50);
            $table->text('details');
            $table->string('client_representative', 255);
            $table->date('decided_on');
            // Reference data sign-off: the version that was reviewed.
            $table->string('reference_version', 20)->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestampTz('created_at');
            $table->bigInteger('chain_seq');
            $table->char('prev_hash', 64);
            $table->char('row_hash', 64);
            $table->index(['decision', 'id']);
            $table->unique('chain_seq');
        });

        DB::table('audit_chain_heads')->insert([
            'table_name' => 'golive_decisions', 'last_seq' => 0,
            'last_hash' => str_repeat('0', 64), 'updated_at' => now(),
        ]);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER golive_decisions_immutable BEFORE UPDATE OR DELETE ON golive_decisions
                FOR EACH ROW EXECUTE FUNCTION buffalo_reject_audit_change();
            CREATE TRIGGER golive_decisions_no_truncate BEFORE TRUNCATE ON golive_decisions
                FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_audit_change();
            CREATE TRIGGER zz_golive_decisions_audit_chain BEFORE INSERT ON golive_decisions
                FOR EACH ROW EXECUTE FUNCTION buffalo_audit_chain('');
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('golive_decisions');
        DB::table('audit_chain_heads')->where('table_name', 'golive_decisions')->delete();
    }
};
