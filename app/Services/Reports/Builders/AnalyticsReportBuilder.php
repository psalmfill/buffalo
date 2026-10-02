<?php

namespace App\Services\Reports\Builders;

use App\Models\Flashpoint;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Analytics\Analytics;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\CoverageAnalytics;
use App\Services\Analytics\ThreatAnalytics;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use App\Support\Classification\ClassificationGate;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * An analytics view as a PDF (Phase 7D): the chosen 7C area with its
 * filters, charts and tables, computed through the requester's scopes
 * (the same cached results as the page, keyed by their scope signature).
 *
 * The level is the highest among the records the figures are drawn from:
 * the period's counted incidents (incidents, casualties, response), threat
 * reports and flashpoints (threats), coverage reports (coverage). Access
 * figures follow the requester's clearance, as the 7C CSV export does.
 */
final class AnalyticsReportBuilder implements ReportBuilder
{
    public function __construct(private readonly PdfRenderer $pdf, private readonly Analytics $analytics) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $area = $this->analytics->area($user, (string) $run->parameters['tab']);

        if ($area === null) {
            throw new AuthorizationException('The analytics area is no longer available to the requester.');
        }

        $filters = AnalyticsFilters::from((array) ($run->parameters['filters'] ?? []), $user);
        $levels = new LevelTracker;

        match ($area->key()) {
            'incidents', 'casualties', 'response' => $levels->seeAll($filters->incidents($user)->toBase()->distinct()->pluck('incidents.classification')),
            'threats' => $levels->seeAll([
                ...app(ThreatAnalytics::class)->threats($user, $filters)->toBase()->distinct()->pluck('threat_reports.classification'),
                ...$filters->narrow(Flashpoint::query()->ownJurisdiction($user), 'flashpoints')->toBase()->distinct()->pluck('flashpoints.classification'),
            ]),
            'coverage' => $levels->seeAll(app(CoverageAnalytics::class)->reports($filters)->toBase()->distinct()->pluck('coverage_reports.classification')),
            default => $levels->see(app(ClassificationGate::class)->maxLevelFor($user)),
        };

        return $this->pdf->render('reports.analytics', [
            'subtitle' => $filters->periodLabel().($filters->hasComparison() ? ' compared with '.$filters->periodLabel(comparison: true) : ''),
            'filters' => $filters->describe(),
            'charts' => $this->analytics->charts($user, $area, $filters),
        ], 'Analytics — '.$area->label(), $run, $user, $levels);
    }
}
