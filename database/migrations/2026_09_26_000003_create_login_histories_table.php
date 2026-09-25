<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of authentication events. A trigger rejects every
 * UPDATE, DELETE and TRUNCATE, so rows cannot be changed or removed through
 * the application or by anyone without the right to drop the trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('email_attempted')->nullable();
            $table->string('event', 40)->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        $events = collect(['login_success', 'login_failed', 'two_factor_failed', 'locked', 'logout', 'session_expired', 'signed_out_elsewhere', 'setup_completed'])
            ->map(fn (string $event) => "'{$event}'")
            ->implode(', ');

        DB::statement("ALTER TABLE login_histories ADD CONSTRAINT login_histories_event_check CHECK (event IN ({$events}))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION login_histories_reject_change() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'login_histories is append-only: % is not allowed', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER login_histories_immutable
                BEFORE UPDATE OR DELETE ON login_histories
                FOR EACH ROW EXECUTE FUNCTION login_histories_reject_change();

            CREATE TRIGGER login_histories_no_truncate
                BEFORE TRUNCATE ON login_histories
                FOR EACH STATEMENT EXECUTE FUNCTION login_histories_reject_change();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('login_histories');
        DB::unprepared('DROP FUNCTION IF EXISTS login_histories_reject_change()');
    }
};
