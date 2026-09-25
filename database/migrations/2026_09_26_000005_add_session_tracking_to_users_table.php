<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Session control independent of the session driver:
 * - session_version: bumped to end every session of a user (deactivation,
 *   two-factor reset). Each session remembers the version it started with.
 * - session_token: random token of the user's latest login, for the
 *   single-active-session rule. The session ID itself is not stored: it is
 *   regenerated after login and would be a hijacking risk if leaked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('session_version')->default(0);
            $table->string('session_token', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['session_version', 'session_token']);
        });
    }
};
