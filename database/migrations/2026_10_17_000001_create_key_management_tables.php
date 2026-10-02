<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Key management (Phase 8A.3).
 *
 * - `key_fingerprints`: a SHA-256 fingerprint of each key (never the key),
 *   per key name: at most one `active` and one `pending` (a rotation in
 *   progress); older ones `retired`. The running keys must match the
 *   active fingerprints, or production refuses to start.
 * - `key_rotations`: a CIPHERSWEET_KEY rotation: from and to fingerprints,
 *   progress per model (resumable), status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('key_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->string('key_name', 32);
            $table->char('fingerprint', 64);
            $table->string('status', 16);
            $table->timestampTz('created_at');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
        });

        DB::statement("ALTER TABLE key_fingerprints ADD CONSTRAINT key_fingerprints_name_valid CHECK (key_name IN ('app_key', 'ciphersweet_key'))");
        DB::statement("ALTER TABLE key_fingerprints ADD CONSTRAINT key_fingerprints_status_valid CHECK (status IN ('active', 'pending', 'retired'))");
        DB::statement("ALTER TABLE key_fingerprints ADD CONSTRAINT key_fingerprints_hex CHECK (fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE key_fingerprints ADD CONSTRAINT key_fingerprints_active_dated CHECK (status <> 'active' OR activated_at IS NOT NULL)");
        DB::statement("CREATE UNIQUE INDEX key_fingerprints_one_active ON key_fingerprints (key_name) WHERE status = 'active'");
        DB::statement("CREATE UNIQUE INDEX key_fingerprints_one_pending ON key_fingerprints (key_name) WHERE status = 'pending'");

        Schema::create('key_rotations', function (Blueprint $table) {
            $table->id();
            $table->string('key_name', 32);
            $table->char('from_fingerprint', 64);
            $table->char('to_fingerprint', 64);
            $table->string('status', 16);
            $table->jsonb('progress')->default('{}');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('updated_at');
            $table->unique(['key_name', 'to_fingerprint']);
        });

        DB::statement("ALTER TABLE key_rotations ADD CONSTRAINT key_rotations_status_valid CHECK (status IN ('running', 'completed'))");
        DB::statement('ALTER TABLE key_rotations ADD CONSTRAINT key_rotations_completed CHECK ((status = \'completed\') = (completed_at IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('key_rotations');
        Schema::dropIfExists('key_fingerprints');
    }
};
