<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dissemination of threat reports and flashpoints (Phase 7A).
 *
 * - `record_disseminations`: the owning state pushes read-only access to a
 *   named state or LGA. Active: not revoked and `expires_at` in the future.
 *   One active dissemination per subject and target. Onward disseminations
 *   (a recipient state forwarding to one of its own LGAs) point at their
 *   parent. `subject_type` is the short type name (never a class name).
 * - `dissemination_requests`: an LGA chief (or the creator) asks the State
 *   Director or Deputy to disseminate; they action or decline it.
 * - `dissemination_gap_alerts`: records the daily cross-border check has
 *   already reported, so each is reported once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_disseminations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('source_state_id')->constrained('states')->restrictOnDelete();
            $table->foreignId('source_lga_id')->constrained('lgas')->restrictOnDelete();
            $table->string('target_level', 8);
            $table->foreignId('target_state_id')->constrained('states')->restrictOnDelete();
            $table->foreignId('target_lga_id')->nullable()->constrained('lgas')->restrictOnDelete();
            $table->foreignId('parent_dissemination_id')->nullable()->constrained('record_disseminations')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('disseminated_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('disseminated_at');
            $table->timestampTz('expires_at');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            // Bookkeeping for the once-only reminder and the expiry notice.
            $table->timestampTz('reminded_at')->nullable();
            $table->timestampTz('expiry_notified_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['source_lga_id', 'source_state_id'], 'record_disseminations_source_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['target_lga_id', 'target_state_id'], 'record_disseminations_target_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['target_state_id', 'subject_type']);
            $table->index(['target_lga_id', 'subject_type']);
            $table->index('parent_dissemination_id');
        });

        DB::statement("ALTER TABLE record_disseminations ADD CONSTRAINT record_disseminations_subject_type CHECK (subject_type IN ('threat_report', 'flashpoint'))");
        DB::statement("ALTER TABLE record_disseminations ADD CONSTRAINT record_disseminations_target_level CHECK (target_level IN ('state', 'lga'))");
        DB::statement("ALTER TABLE record_disseminations ADD CONSTRAINT record_disseminations_target_lga CHECK ((target_level = 'lga') = (target_lga_id IS NOT NULL))");
        DB::statement('ALTER TABLE record_disseminations ADD CONSTRAINT record_disseminations_reason CHECK (char_length(btrim(reason)) >= 20)');
        // One active (unrevoked) dissemination per subject and target. Expired
        // rows are released by renewing (flashpoints) or by the threat's
        // expiry moving (threat reports), so they keep the slot as well.
        DB::statement('CREATE UNIQUE INDEX record_disseminations_one_active ON record_disseminations (subject_type, subject_id, target_level, target_state_id, COALESCE(target_lga_id, 0)) WHERE revoked_at IS NULL');

        Schema::create('dissemination_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->jsonb('suggested_targets');
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();

            $table->foreign(['lga_id', 'state_id'], 'dissemination_requests_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['state_id', 'status']);
        });

        DB::statement("ALTER TABLE dissemination_requests ADD CONSTRAINT dissemination_requests_subject_type CHECK (subject_type IN ('threat_report', 'flashpoint'))");
        DB::statement("ALTER TABLE dissemination_requests ADD CONSTRAINT dissemination_requests_status CHECK (status IN ('pending', 'actioned', 'declined'))");
        DB::statement('ALTER TABLE dissemination_requests ADD CONSTRAINT dissemination_requests_reason CHECK (char_length(btrim(reason)) >= 20)');
        DB::statement("ALTER TABLE dissemination_requests ADD CONSTRAINT dissemination_requests_decline_note CHECK (status <> 'declined' OR (decision_note IS NOT NULL AND btrim(decision_note) <> ''))");
        DB::statement("CREATE UNIQUE INDEX dissemination_requests_one_pending ON dissemination_requests (subject_type, subject_id, requested_by) WHERE status = 'pending'");

        Schema::create('dissemination_gap_alerts', function (Blueprint $table) {
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->timestampTz('alerted_at');
            $table->primary(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dissemination_gap_alerts');
        Schema::dropIfExists('dissemination_requests');
        Schema::dropIfExists('record_disseminations');
    }
};
