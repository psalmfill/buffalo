<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback from user acceptance testing (Phase 9B.5): short text, the page
 * and the tester's role. Written only in the UAT environment (the routes
 * do not exist elsewhere); the table stays empty in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uat_feedback', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->string('role', 40);
            $table->string('page_url', 500);
            $table->text('text');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uat_feedback');
    }
};
