<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix (found in Phase 10A): buffalo_incident_child_locked checked only the
 * row's NEW incident, so a row could be moved OFF a closed or invalid
 * incident onto an open one. It now refuses the change when either the old
 * or the new incident is locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_incident_child_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                incident_status text;
            BEGIN
                SELECT status INTO incident_status FROM incidents
                 WHERE id IN (NEW.incident_id, OLD.incident_id) AND status IN ('closed', 'invalid') LIMIT 1;
                IF incident_status IS NOT NULL THEN
                    RAISE EXCEPTION 'The incident is %: it is locked.', incident_status USING ERRCODE = 'check_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_incident_child_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                incident_status text;
            BEGIN
                SELECT status INTO incident_status FROM incidents WHERE id = COALESCE(NEW.incident_id, OLD.incident_id);
                IF incident_status IN ('closed', 'invalid') THEN
                    RAISE EXCEPTION 'The incident is %: it is locked.', incident_status USING ERRCODE = 'check_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;
            SQL);
    }
};
