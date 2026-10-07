<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pilot rollout (Phase 9B.3): a state is live once the super admin switches
 * it on. Until then its state- and LGA-level users cannot sign in, which is
 * recorded in login history as `state_not_live`. Every state starts not
 * live, including on upgraded installations.
 */
return new class extends Migration
{
    private const BEFORE = ['login_success', 'login_failed', 'two_factor_failed', 'locked', 'logout', 'session_expired', 'signed_out_elsewhere', 'setup_completed', 'step_up_success', 'step_up_failed'];

    public function up(): void
    {
        Schema::table('states', function (Blueprint $table) {
            $table->boolean('is_live')->default(false);
            $table->timestampTz('went_live_at')->nullable();
        });

        $this->constrain([...self::BEFORE, 'state_not_live']);
    }

    public function down(): void
    {
        $this->constrain(self::BEFORE);

        Schema::table('states', function (Blueprint $table) {
            $table->dropColumn(['is_live', 'went_live_at']);
        });
    }

    /** @param list<string> $events */
    private function constrain(array $events): void
    {
        $list = collect($events)->map(fn (string $e) => "'{$e}'")->implode(', ');
        DB::statement('ALTER TABLE login_histories DROP CONSTRAINT login_histories_event_check');
        DB::statement("ALTER TABLE login_histories ADD CONSTRAINT login_histories_event_check CHECK (event IN ({$list}))");
    }
};
