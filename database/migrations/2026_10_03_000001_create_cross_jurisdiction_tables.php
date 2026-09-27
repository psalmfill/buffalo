<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-jurisdiction matching, approval delegation, access requests and
 * grants (Phase 3C, CLAUDE.md section 4 rule 8).
 *
 * - record_match_events is an audit table: append-only (shared trigger).
 * - Requests are never deleted (no soft deletes); their status only moves
 *   forward (CHECK on values; the Actions enforce the transitions).
 * - Grants are ended by expiry or revocation, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_match_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('owning_state_id')->constrained('states')->restrictOnDelete();
            $table->foreignId('owning_lga_id')->nullable()->constrained('lgas')->restrictOnDelete();
            $table->string('match_type', 16);
            $table->string('context', 32);
            // A match above the searcher's clearance: recorded, never shown.
            $table->boolean('suppressed')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['owning_state_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE record_match_events ADD CONSTRAINT record_match_events_type_valid CHECK (match_type IN ('exact', 'strong', 'possible'))");
        DB::statement("ALTER TABLE record_match_events ADD CONSTRAINT record_match_events_context_valid CHECK (context IN ('person_search', 'person_create', 'incident_entry'))");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER record_match_events_immutable
                BEFORE UPDATE OR DELETE ON record_match_events
                FOR EACH ROW EXECUTE FUNCTION buffalo_reject_audit_change();

            CREATE TRIGGER record_match_events_no_truncate
                BEFORE TRUNCATE ON record_match_events
                FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_audit_change();
            SQL);

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('delegator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_id')->index()->constrained('users')->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            // Why it ended early: revoked, or a posting of either party closed.
            $table->string('end_reason', 32)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_not_self CHECK (delegator_id <> delegate_id)');
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_dates_ordered CHECK (ends_at IS NULL OR ends_at > starts_at)');

        Schema::create('record_access_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('requester_id')->index()->constrained('users')->restrictOnDelete();
            $table->foreignId('requester_state_id')->nullable()->constrained('states')->restrictOnDelete();
            $table->foreignId('requester_lga_id')->nullable()->constrained('lgas')->restrictOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('owning_state_id')->index()->constrained('states')->restrictOnDelete();
            $table->foreignId('owning_lga_id')->nullable()->constrained('lgas')->restrictOnDelete();
            $table->foreignId('match_event_id')->constrained('record_match_events')->restrictOnDelete();
            $table->text('reason');
            $table->string('reference', 100)->nullable();
            $table->string('scope', 16);
            $table->string('urgency', 16);
            $table->smallInteger('requested_days');
            $table->string('status', 16)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->smallInteger('granted_days')->nullable();
            $table->string('granted_scope', 16)->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE record_access_requests ADD CONSTRAINT record_access_requests_scope_valid CHECK (scope IN ('profile', 'full') AND (granted_scope IS NULL OR granted_scope IN ('profile', 'full')))");
        DB::statement("ALTER TABLE record_access_requests ADD CONSTRAINT record_access_requests_urgency_valid CHECK (urgency IN ('normal', 'urgent'))");
        DB::statement('ALTER TABLE record_access_requests ADD CONSTRAINT record_access_requests_days_valid CHECK (requested_days IN (7, 14, 30) AND (granted_days IS NULL OR (granted_days BETWEEN 1 AND requested_days)))');
        DB::statement("ALTER TABLE record_access_requests ADD CONSTRAINT record_access_requests_status_valid CHECK (status IN ('pending', 'escalated', 'approved', 'rejected', 'cancelled', 'expired'))");
        DB::statement('ALTER TABLE record_access_requests ADD CONSTRAINT record_access_requests_reason_length CHECK (char_length(reason) >= 30)');
        // Never widen: a profile request cannot be granted full.
        DB::statement("ALTER TABLE record_access_requests ADD CONSTRAINT record_access_requests_never_widen CHECK (NOT (scope = 'profile' AND granted_scope = 'full'))");
        DB::statement("ALTER TABLE record_access_requests ADD CONSTRAINT record_access_requests_rejection_note CHECK (status <> 'rejected' OR (decision_note IS NOT NULL AND btrim(decision_note) <> ''))");
        // One open request per requester and record.
        DB::statement("CREATE UNIQUE INDEX record_access_requests_one_open ON record_access_requests (requester_id, subject_type, subject_id) WHERE status IN ('pending', 'escalated')");

        Schema::create('record_access_grants', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('request_id')->unique()->constrained('record_access_requests')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('scope', 16);
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            // Bookkeeping for the expiry job's notifications.
            $table->timestamp('expiry_reminded_at')->nullable();
            $table->timestamp('expiry_notified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'subject_type', 'subject_id']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE record_access_grants ADD CONSTRAINT record_access_grants_scope_valid CHECK (scope IN ('profile', 'full'))");
        DB::statement('ALTER TABLE record_access_grants ADD CONSTRAINT record_access_grants_dates_ordered CHECK (expires_at > starts_at)');
        DB::statement("ALTER TABLE record_access_grants ADD CONSTRAINT record_access_grants_revocation_reason CHECK (revoked_at IS NULL OR (revocation_reason IS NOT NULL AND btrim(revocation_reason) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('record_access_grants');
        Schema::dropIfExists('record_access_requests');
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('record_match_events');
    }
};
