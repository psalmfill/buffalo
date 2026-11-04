<?php

namespace App\Actions\Security;

use App\Enums\CoverageRating;
use App\Enums\CoverageReportStatus;
use App\Enums\Role;
use App\Models\CoverageReport;
use App\Models\User;
use App\Notifications\CoverageCriticalRating;
use App\Notifications\CoverageReportSubmitted;
use App\Support\Audit\AuditLog;
use App\Support\Notifications\NotifyRole;
use App\Support\Security\CoverageSnapshot;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Submits a draft or returned security report for review (Phase 4A; only
 * the written report is required since Phase 11G). The
 * complete version is snapshotted in the activity log. The State Director
 * and Deputy are notified; a `critical` rating also alerts the DG and the
 * DDGs covering the state.
 */
final class SubmitCoverageReport
{
    public function __construct(private readonly NotifyRole $notify) {}

    public function __invoke(CoverageReport $report, User $actor): void
    {
        if (! $report->status->isEditable()) {
            throw new InvalidArgumentException('Only draft or returned security reports can be submitted.');
        }

        $rating = $report->overall_rating;

        // Only the written report is required (Phase 11G).
        if ($report->summary === null || trim((string) $report->summary_text) === '') {
            throw new InvalidArgumentException('The security report has no text.');
        }

        $resubmission = $report->status === CoverageReportStatus::Returned;

        DB::transaction(function () use ($report, $actor, $resubmission): void {
            $report->forceFill([
                'status' => CoverageReportStatus::Submitted,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            AuditLog::event(
                'coverage_report_submitted',
                $resubmission ? 'Security report resubmitted' : 'Security report submitted',
                $report,
                ['resubmission' => $resubmission, 'version' => CoverageSnapshot::of($report)],
                $actor,
            );
        });

        $report->loadMissing(['lga', 'state']);
        $period = $report->periodLabel();
        $submitted = new CoverageReportSubmitted($report->ulid, $report->lga->name, $period);
        $this->notify->send($submitted, Role::StateDirector, $report->state);
        $this->notify->send($submitted, Role::DeputyStateDirector, $report->state);

        if ($rating === CoverageRating::Critical) {
            $alert = new CoverageCriticalRating($report->ulid, $report->lga->name, $report->state->name, $period);
            $this->notify->send($alert, Role::DirectorGeneral, $report->state);
            $this->notify->send($alert, Role::DeputyDirectorGeneral, $report->state);
        }
    }
}
