<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit records are never editable or deletable (CLAUDE.md section 6).
 * A shared trigger function rejects UPDATE, DELETE and TRUNCATE; it is
 * attached to activity_log here and reused by other append-only tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_reject_audit_change() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: % is not allowed', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER activity_log_immutable
                BEFORE UPDATE OR DELETE ON activity_log
                FOR EACH ROW EXECUTE FUNCTION buffalo_reject_audit_change();

            CREATE TRIGGER activity_log_no_truncate
                BEFORE TRUNCATE ON activity_log
                FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_audit_change();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS activity_log_immutable ON activity_log;
            DROP TRIGGER IF EXISTS activity_log_no_truncate ON activity_log;
            SQL);
    }
};
