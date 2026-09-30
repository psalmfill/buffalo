<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security coverage reports (Phase 4A): an LGA's statement, for a period,
 * of which agencies are deployed where, what they cover and where the gaps
 * are.
 *
 * Database guarantees:
 * - composite FKs keep deployments and their communities in the report's
 *   LGA (access rule 9: deployments carry their own state_id/lga_id);
 * - weekly, monthly and quarterly reports match the period boundaries, and
 *   there is one non-deleted report per LGA, period type and start;
 * - a deployment's formation belongs to the deployment's agency and state
 *   (trigger);
 * - a reviewed report is locked: its row, deployments and their communities
 *   reject every change (triggers);
 * - coverage_reminders records the reminders sent, once per period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_reports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->string('period_type', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('overall_rating', 16)->nullable();
            $table->text('summary')->nullable();
            $table->text('gaps_identified')->nullable();
            $table->text('recommendations')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->string('classification', 16)->default('restricted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['lga_id', 'state_id'], 'coverage_reports_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id', 'lga_id', 'state_id'], 'coverage_reports_id_lga_state_unique');
            $table->index(['lga_id', 'period_type', 'period_start']);
            $table->index(['status']);
        });

        DB::statement("ALTER TABLE coverage_reports ADD CONSTRAINT coverage_reports_period_type_valid CHECK (period_type IN ('weekly', 'monthly', 'quarterly', 'ad_hoc'))");
        DB::statement("ALTER TABLE coverage_reports ADD CONSTRAINT coverage_reports_rating_valid CHECK (overall_rating IS NULL OR overall_rating IN ('adequate', 'partial', 'inadequate', 'critical'))");
        DB::statement("ALTER TABLE coverage_reports ADD CONSTRAINT coverage_reports_status_valid CHECK (status IN ('draft', 'submitted', 'returned', 'reviewed'))");
        DB::statement("ALTER TABLE coverage_reports ADD CONSTRAINT coverage_reports_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement('ALTER TABLE coverage_reports ADD CONSTRAINT coverage_reports_period_ordered CHECK (period_end >= period_start)');
        DB::statement(<<<'SQL'
            ALTER TABLE coverage_reports ADD CONSTRAINT coverage_reports_period_boundaries CHECK (
                period_type = 'ad_hoc'
                OR (period_type = 'weekly' AND EXTRACT(ISODOW FROM period_start) = 1 AND period_end = period_start + 6)
                OR (period_type = 'monthly' AND period_start = date_trunc('month', period_start)::date
                    AND period_end = (date_trunc('month', period_start) + interval '1 month - 1 day')::date)
                OR (period_type = 'quarterly' AND period_start = date_trunc('quarter', period_start)::date
                    AND period_end = (date_trunc('quarter', period_start) + interval '3 months - 1 day')::date)
            )
        SQL);
        // Anything past draft has been submitted, and carries a rating.
        DB::statement("ALTER TABLE coverage_reports ADD CONSTRAINT coverage_reports_submitted_complete CHECK (status = 'draft' OR (submitted_at IS NOT NULL AND overall_rating IS NOT NULL AND summary IS NOT NULL))");
        DB::statement("ALTER TABLE coverage_reports ADD CONSTRAINT coverage_reports_reviewed_complete CHECK (status <> 'reviewed' OR reviewed_at IS NOT NULL)");
        DB::statement("ALTER TABLE coverage_reports ADD CONSTRAINT coverage_reports_returned_reason CHECK (status <> 'returned' OR (returned_at IS NOT NULL AND return_reason IS NOT NULL AND btrim(return_reason) <> ''))");
        DB::statement("CREATE UNIQUE INDEX coverage_reports_one_per_period ON coverage_reports (lga_id, period_type, period_start) WHERE deleted_at IS NULL AND period_type <> 'ad_hoc'");

        Schema::create('coverage_report_deployments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('coverage_report_id')->index();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('security_agency_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('agency_formation_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->integer('personnel_count');
            $table->integer('vehicles_count')->nullable();
            $table->string('patrol_frequency', 16);
            $table->text('areas_covered')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The deployment is in the report's LGA and state.
            $table->foreign(['coverage_report_id', 'lga_id', 'state_id'], 'coverage_deployments_same_report_place_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('coverage_reports')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['lga_id', 'state_id'], 'coverage_deployments_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id', 'lga_id'], 'coverage_deployments_id_lga_unique');
        });

        DB::statement('ALTER TABLE coverage_report_deployments ADD CONSTRAINT coverage_deployments_personnel_non_negative CHECK (personnel_count >= 0)');
        DB::statement('ALTER TABLE coverage_report_deployments ADD CONSTRAINT coverage_deployments_vehicles_non_negative CHECK (vehicles_count IS NULL OR vehicles_count >= 0)');
        DB::statement("ALTER TABLE coverage_report_deployments ADD CONSTRAINT coverage_deployments_patrol_valid CHECK (patrol_frequency IN ('continuous', 'daily', 'several_weekly', 'weekly', 'occasional', 'none'))");

        // Which communities each deployment covers; the community must be in
        // the deployment's (= the report's) LGA.
        Schema::create('coverage_deployment_communities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coverage_report_deployment_id');
            $table->foreignId('community_id')->index();
            $table->foreignId('lga_id')->index();

            $table->foreign(['coverage_report_deployment_id', 'lga_id'], 'coverage_deployment_communities_deployment_foreign')
                ->references(['id', 'lga_id'])->on('coverage_report_deployments')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign(['community_id', 'lga_id'], 'coverage_deployment_communities_community_foreign')
                ->references(['id', 'lga_id'])->on('communities')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['coverage_report_deployment_id', 'community_id'], 'coverage_deployment_communities_unique');
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_coverage_deployment_formation() RETURNS trigger AS $$
            DECLARE
                formation record;
            BEGIN
                IF NEW.agency_formation_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT security_agency_id, state_id INTO formation FROM agency_formations WHERE id = NEW.agency_formation_id;

                IF formation.security_agency_id IS DISTINCT FROM NEW.security_agency_id OR formation.state_id IS DISTINCT FROM NEW.state_id THEN
                    RAISE EXCEPTION 'a deployment''s formation must belong to its agency and be in the same state'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER coverage_deployments_formation BEFORE INSERT OR UPDATE OF agency_formation_id, security_agency_id, state_id ON coverage_report_deployments FOR EACH ROW EXECUTE FUNCTION buffalo_coverage_deployment_formation()');

        // Reviewed reports are locked, at the database level too.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION buffalo_coverage_report_locked() RETURNS trigger AS $$
            DECLARE
                report_status text;
            BEGIN
                IF TG_TABLE_NAME = 'coverage_reports' THEN
                    report_status := OLD.status;
                ELSIF TG_TABLE_NAME = 'coverage_report_deployments' THEN
                    SELECT status INTO report_status FROM coverage_reports
                        WHERE id = COALESCE(NEW.coverage_report_id, OLD.coverage_report_id);
                ELSE
                    SELECT r.status INTO report_status FROM coverage_reports r
                        JOIN coverage_report_deployments d ON d.coverage_report_id = r.id
                        WHERE d.id = COALESCE(NEW.coverage_report_deployment_id, OLD.coverage_report_deployment_id);
                END IF;

                IF report_status = 'reviewed' THEN
                    RAISE EXCEPTION 'a reviewed coverage report is locked'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER coverage_reports_locked BEFORE UPDATE OR DELETE ON coverage_reports FOR EACH ROW EXECUTE FUNCTION buffalo_coverage_report_locked()');
        DB::statement('CREATE TRIGGER coverage_deployments_locked BEFORE INSERT OR UPDATE OR DELETE ON coverage_report_deployments FOR EACH ROW EXECUTE FUNCTION buffalo_coverage_report_locked()');
        DB::statement('CREATE TRIGGER coverage_deployment_communities_locked BEFORE INSERT OR UPDATE OR DELETE ON coverage_deployment_communities FOR EACH ROW EXECUTE FUNCTION buffalo_coverage_report_locked()');

        // Reminders already sent (once per period and recipient place).
        Schema::create('coverage_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 8);
            $table->unsignedBigInteger('place_id');
            $table->string('period_type', 16);
            $table->date('period_start');
            $table->timestamp('sent_at');

            $table->unique(['scope', 'place_id', 'period_type', 'period_start'], 'coverage_reminders_once');
        });

        DB::statement("ALTER TABLE coverage_reminders ADD CONSTRAINT coverage_reminders_scope_valid CHECK (scope IN ('lga', 'state'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_reminders');
        Schema::dropIfExists('coverage_deployment_communities');
        Schema::dropIfExists('coverage_report_deployments');
        Schema::dropIfExists('coverage_reports');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_coverage_report_locked()');
        DB::statement('DROP FUNCTION IF EXISTS buffalo_coverage_deployment_formation()');
    }
};
