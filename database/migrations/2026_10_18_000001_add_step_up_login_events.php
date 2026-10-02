<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Login history event types for step-up authentication (Phase 8B.2).
 */
return new class extends Migration
{
    private const BEFORE = ['login_success', 'login_failed', 'two_factor_failed', 'locked', 'logout', 'session_expired', 'signed_out_elsewhere', 'setup_completed'];

    public function up(): void
    {
        $this->constrain([...self::BEFORE, 'step_up_success', 'step_up_failed']);
    }

    public function down(): void
    {
        $this->constrain(self::BEFORE);
    }

    /** @param list<string> $events */
    private function constrain(array $events): void
    {
        $list = collect($events)->map(fn (string $e) => "'{$e}'")->implode(', ');
        DB::statement('ALTER TABLE login_histories DROP CONSTRAINT login_histories_event_check');
        DB::statement("ALTER TABLE login_histories ADD CONSTRAINT login_histories_event_check CHECK (event IN ({$list}))");
    }
};
