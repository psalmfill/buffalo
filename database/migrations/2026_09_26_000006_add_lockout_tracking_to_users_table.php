<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escalating lockout: each lock within 24 hours of the previous one doubles
 * the lock duration (App\Actions\Auth\RecordFailedLogin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('lockout_count')->default(0);
            $table->timestamp('last_locked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['lockout_count', 'last_locked_at']);
        });
    }
};
