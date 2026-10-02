<?php

namespace App\Services\Reports\Builders;

use App\Http\Presenters\CoverageReportPresenter;
use App\Models\CoverageReport;
use App\Models\CoverageReportDeployment;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use App\Support\Security\CoverageAnalysis;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * One coverage report as a PDF (Phase 7D): the report, its deployments
 * (formations the requester cannot see are not named), totals by agency,
 * uncovered communities (names only, no coordinates) and the workflow
 * history. The level is the report's own classification, raised by any
 * formation it names.
 */
final class CoverageReportBuilder implements ReportBuilder
{
    public function __construct(private readonly PdfRenderer $pdf, private readonly CoverageAnalysis $analysis) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $report = CoverageReport::query()->where('ulid', (string) $run->parameters['report'])->first();

        if ($report === null || ! $user->can('view', $report)) {
            throw new AuthorizationException('The coverage report is no longer visible to the requester.');
        }

        $report->recordAccess(context: 'report');
        $report->load(['lga', 'state']);
        $deployments = CoverageReportDeployment::query()->with(['agency', 'formation', 'communities'])
            ->where('coverage_report_id', $report->id)->orderBy('id')->get();
        $levels = new LevelTracker;
        $levels->see($report->classificationLevel());
        $levels->seeAll($deployments->map(fn (CoverageReportDeployment $d) => $d->formation?->classificationLevel()));

        return $this->pdf->render('reports.coverage', [
            'report' => CoverageReportPresenter::detail($report),
            'deployments' => $deployments->map(fn (CoverageReportDeployment $d) => CoverageReportPresenter::deployment($d))->all(),
            'totals' => $this->analysis->totals($report),
            'uncovered' => $this->analysis->uncovered($report)->pluck('name')->all(),
            'timeline' => CoverageReportPresenter::timeline($report),
        ], 'Coverage report — '.$report->lga->name.' LGA, '.$report->periodLabel(), $run, $user, $levels);
    }
}
