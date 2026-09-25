<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads of persons, persons of interest and classified records (CLAUDE.md
 * section 6). Append-only: the shared audit trigger rejects UPDATE, DELETE
 * and TRUNCATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 20);
            $table->string('context', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE record_access_logs ADD CONSTRAINT record_access_logs_action_check CHECK (action IN ('view', 'export', 'print', 'download'))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER record_access_logs_immutable
                BEFORE UPDATE OR DELETE ON record_access_logs
                FOR EACH ROW EXECUTE FUNCTION buffalo_reject_audit_change();

            CREATE TRIGGER record_access_logs_no_truncate
                BEFORE TRUNCATE ON record_access_logs
                FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_audit_change();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('record_access_logs');
    }
};
